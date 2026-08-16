<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Cleans HTML coming from the Quill rich-text fields before it is stored.
 *
 * Rich text means the browser sends markup the user controls, and that markup is rendered
 * back into other people's pages — so it is sanitized on the way IN, once, rather than
 * escaped on every read. Anything not on the allowlist below is dropped.
 *
 * The allowlist is deliberately the shape of the editor's toolbar (Quill, configured in
 * `public/assets/js/projects/work-items.js`): if a button cannot produce a tag, the tag has no
 * reason to survive sanitizing. Both of Quill's formatting mechanisms are covered — `class`,
 * which is how it stores alignment, indentation and code blocks, and `style`, which is how it
 * stores colour — and the style value is run through a guard that strips the CSS constructs
 * used to smuggle script or exfiltrate data.
 */
class RichTextSanitizer
{
    /** Elements the editor's toolbar can produce. */
    private const ELEMENTS = [
        'p', 'br', 'div', 'span',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'mark',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        // The image and video buttons wrap their media in a figure.
        'figure', 'figcaption',
    ];

    /** Elements that may carry inline styling (alignment, colour, table widths). */
    private const STYLEABLE = ['p', 'div', 'span', 'li', 'td', 'th', 'table', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * Hosts an embedded video may come from.
     *
     * An `<iframe>` is a page inside the page, so it is allowed only from providers we
     * name — an open iframe would let anyone paste a login form onto a teammate's screen.
     */
    private const VIDEO_HOSTS = [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com',
        'player.vimeo.com', 'vimeo.com',
    ];

    /** CSS that never has a legitimate place in a description. */
    private const CSS_BLOCKED = '/(expression\s*\(|javascript\s*:|behavior\s*:|@import|url\s*\()/i';

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $clean = trim($this->sanitizer()->sanitize($html));

        // An editor with nothing in it still emits scaffolding ("<p><br></p>"). Store that as
        // an empty description so "has a description" stays a meaningful question.
        return $this->isBlank($clean) ? null : $clean;
    }

    /**
     * Convert pre-rich-text plain text into equivalent HTML.
     *
     * Descriptions written before the editor existed are literal text: rendering them as HTML
     * would both lose their line breaks and interpret any angle brackets the author typed.
     */
    public function fromPlainText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];

        return implode('', array_map(
            fn (string $p) => '<p>'.nl2br(e(trim($p))).'</p>',
            array_filter($paragraphs, fn (string $p) => trim($p) !== ''),
        ));
    }

    /** A short plain-text excerpt — for audit rows and anywhere HTML would be noise. */
    public function excerpt(?string $html, int $length = 200): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))) ?? '');

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        // Cut on a word boundary and say that it was cut. Slicing mid-word reads as a bug —
        // "review the automat" — and without the ellipsis nobody can tell a truncated
        // description from a short one.
        $cut = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace > $length * 0.6 ? mb_substr($cut, 0, $lastSpace) : $cut, " \t\n,;:").'…';
    }

    private function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            // http as well as https: local and staging hosts are not on TLS, and uploaded
            // images are served from this application's own origin.
            ->allowMediaSchemes(['http', 'https'])
            ->allowMediaHosts(array_merge(self::VIDEO_HOSTS, self::ownHost()))
            ->allowRelativeLinks()
            ->allowRelativeMedias();

        foreach (self::ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }

        $config = $config
            ->allowElement('a', ['href', 'title', 'target', 'rel'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('iframe', ['src', 'width', 'height', 'title', 'allowfullscreen', 'frameborder'])
            ->allowAttribute('class', self::ELEMENTS)
            // §25: the mention chip's own metadata, and nothing more. `data-user-id` is what
            // makes a mention resolvable to a person after they have been renamed (§8), so it
            // has to survive sanitizing — but it survives as DATA, never as authority: §24
            // requires the backend to verify the id names a real, mentionable user before any
            // record or notification comes of it. A crafted `data-user-id="999"` reaching the
            // database is expected and harmless; MentionSync is what refuses it.
            ->allowAttribute('data-user-id', ['span'])
            ->allowAttribute('data-mention-type', ['span'])
            // Quill marks bullet vs ordered items with `data-list` rather than the wrapping
            // element. Dropping it would silently turn every bullet list into a numbered one.
            ->allowAttribute('data-list', ['li'])
            ->allowAttribute('colspan', ['td', 'th'])
            ->allowAttribute('rowspan', ['td', 'th'])
            ->allowAttribute('style', self::STYLEABLE)
            ->withAttributeSanitizer(new class implements AttributeSanitizerInterface
            {
                public function getSupportedElements(): ?array
                {
                    return null; // every element
                }

                public function getSupportedAttributes(): ?array
                {
                    return ['style'];
                }

                public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
                {
                    return preg_match(RichTextSanitizer::cssBlockedPattern(), $value) ? null : $value;
                }
            });

        return new HtmlSanitizer($config);
    }

    /**
     * This application's own host, so uploaded images — served from an authorized route on
     * this domain — are not dropped by the media host allowlist that exists for iframes.
     *
     * @return array<int, string>
     */
    private static function ownHost(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host ? [$host] : [];
    }

    /** Exposed so the inline attribute sanitizer above can reuse the one pattern. */
    public static function cssBlockedPattern(): string
    {
        return self::CSS_BLOCKED;
    }

    /**
     * Is this content-free scaffolding? Text alone is not the test: an image, an embedded
     * video, a table or a rule is a description with no words in it, and dropping those as
     * "blank" would silently delete what the author just inserted.
     */
    private function isBlank(string $html): bool
    {
        if (preg_match('/<(img|iframe|table|hr)\b/i', $html)) {
            return false;
        }

        return trim(str_replace('&nbsp;', '', strip_tags($html))) === '';
    }
}

<?php

namespace App\Services\HelpCenter\Inbound;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Keeping the customer's NEW words and dropping the thread underneath them
 * (docs/features/help-center.md, P68).
 *
 * A reply arrives carrying the whole conversation: the customer's two new sentences, then the
 * message they answered, then the one before that. Stored whole, a ticket's timeline repeats
 * itself further with every exchange, and the one thing an agent needs — what was just said —
 * is the smallest part of it.
 *
 * ## Nothing here is ever destructive
 *
 * The original body is kept on the message (`raw_text` / `raw_html`) before this runs. That is
 * what makes the rules below safe to be aggressive: a mistake costs a bad-looking comment, not a
 * lost sentence, and the raw copy is there to check against.
 *
 * ## The rule when it is not sure
 *
 * "If the parser cannot confidently determine where the quoted content starts: do not discard
 * the entire message." So every cut is checked — a strip that leaves nothing when there WAS
 * something is refused and the original kept, with `confident: false` for the caller to log.
 * A reply that is genuinely nothing but a quote is the one case where empty is the honest
 * answer, and it is left as it was rather than blanked.
 */
class QuotedReplyStripper
{
    /**
     * Plain text: cut at the first line that begins somebody else's message.
     *
     * @return array{clean: string, confident: bool}
     */
    public function text(?string $body): array
    {
        $original = (string) $body;

        if (trim($original) === '') {
            return ['clean' => $original, 'confident' => true];
        }

        $lines = preg_split("/\r\n|\r|\n/", $this->stripMimeNoise($original)) ?: [];
        $cut = $this->cutAt($lines);

        if ($cut === null) {
            return ['clean' => $this->tidy($this->stripMimeNoise($original)), 'confident' => true];
        }

        $clean = $this->tidy(implode("\n", array_slice($lines, 0, $cut)));

        /*
         * A cut that removed EVERYTHING is a cut in the wrong place.
         *
         * The commonest cause is a client that puts the attribution line first — "On … wrote:"
         * at the very top, with the new words below it. Keeping the original is wrong-looking;
         * keeping nothing is data loss, and the requirement is explicit about which is worse.
         */
        if ($clean === '') {
            return ['clean' => $this->tidy($original), 'confident' => false];
        }

        return ['clean' => $clean, 'confident' => true];
    }

    /**
     * HTML: drop the containers every mail client wraps its quoted history in.
     *
     * By CONTAINER rather than by text, because the markup says plainly what the prose can only
     * be guessed at: Gmail wraps history in `.gmail_quote`, Apple Mail in
     * `blockquote[type=cite]`, Outlook after `#divRplyFwdMsg`, Yahoo in `.yahoo_quoted`. Each is
     * an unambiguous statement by the sender's own client that what follows is not new.
     *
     * @return array{clean: string, confident: bool}
     */
    public function html(?string $body): array
    {
        $original = (string) $body;

        if (trim(strip_tags($original)) === '') {
            return ['clean' => $original, 'confident' => true];
        }

        $doc = $this->parse($original);

        if ($doc === null) {
            return ['clean' => $original, 'confident' => false];
        }

        $xpath = new DOMXPath($doc);
        $removed = 0;

        foreach ($this->quoteQueries() as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            // Collected first: removing while iterating a live NodeList skips siblings.
            $doomed = [];

            foreach ($nodes as $node) {
                $doomed[] = $node;
            }

            foreach ($doomed as $node) {
                /*
                 * Everything AFTER it goes too, not only the node.
                 *
                 * Outlook's `#divRplyFwdMsg` is a HEADER — "From: … Sent: … To:" — and the
                 * quoted body is its siblings, not its children. Removing the marker alone
                 * would delete the label and keep the thread.
                 */
                $this->removeFollowing($node);
                $this->remove($node);
                $removed++;
            }
        }

        // Thunderbird and several webmails: a "wrote:" line, then the quote as its sibling.
        $removed += $this->removeAfterAttribution($doc, $xpath);

        if ($removed === 0) {
            return ['clean' => $original, 'confident' => true];
        }

        $clean = $this->innerHtml($doc);

        // Same rule the text path applies: an empty result from a non-empty body is a bad cut.
        if (trim(strip_tags($clean)) === '') {
            return ['clean' => $original, 'confident' => false];
        }

        return ['clean' => $clean, 'confident' => true];
    }

    /**
     * The line where the previous message starts, or null.
     *
     * @param  array<int, string>  $lines
     */
    private function cutAt(array $lines): ?int
    {
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if ($this->isAttribution($lines, $i)) {
                return $i;
            }

            // "-----Original Message-----", "--- Original Message ---", "----- Forwarded
            // Message -----", "Begin forwarded message:".
            if (preg_match('/^\s*-{2,}\s*(original|forwarded)\s+message\s*-{2,}\s*$/i', $trimmed) === 1
                || preg_match('/^\s*begin\s+forwarded\s+message\s*:?\s*$/i', $trimmed) === 1) {
                return $i;
            }

            /*
             * Outlook's horizontal rule — a long run of underscores or dashes on its own line.
             *
             * Only when a header block follows within a few lines. A row of underscores is also
             * something people type as a separator above their own signature, and cutting on it
             * unconditionally would eat the end of a genuine message.
             */
            if (preg_match('/^\s*[_]{10,}\s*$/', $trimmed) === 1 && $this->headerBlockNear($lines, $i + 1)) {
                return $i;
            }

            // A bare Outlook header block, with no rule above it.
            if (preg_match('/^\s*from\s*:\s*\S/i', $trimmed) === 1 && $this->headerBlockNear($lines, $i)) {
                return $i;
            }

            // A run of `>` quoting that continues to the end — no attribution line, which is
            // how several mobile clients and mailing lists quote.
            if (str_starts_with($trimmed, '>') && $this->quotedToEnd($lines, $i)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * "On <date>, <someone> wrote:" — possibly spread over several lines.
     *
     * Gmail wraps a long attribution, so the real data looks like:
     *
     *     On Sat, 22 Aug 2026 at 13:43, eBay Support System <
     *     inbox-wknbatec@inbound.myprojectblock.dev> wrote:
     *
     * Matching only single lines would miss exactly the case that prompted this. So a line
     * opening with `On ` is joined with the next few and the whole is tested — bounded, because
     * an unbounded search would find a "wrote:" anywhere below and cut the message at its top.
     *
     * @param  array<int, string>  $lines
     */
    private function isAttribution(array $lines, int $index): bool
    {
        $line = trim($lines[$index] ?? '');

        /*
         * The attribution must BEGIN on this line — checked before the window is joined.
         *
         * The first version of this tested a four-line window against
         * `<something@example.com> wrote:` with 200 characters of anything allowed in front,
         * which reached forward out of the line being tested and into the next one's
         * attribution. On the real Gmail body that matched at line 0, cut the message at its
         * own first word, and fell back to storing the whole thread — the exact bug this class
         * exists to fix, reintroduced by the rule meant to fix it.
         */
        if (preg_match('/^on\b/i', $line) === 1) {
            $window = (string) preg_replace('/\s+/', ' ', trim(implode(' ', array_slice($lines, $index, 4))));

            // Joined with the next few lines because Gmail WRAPS a long attribution — the
            // "wrote:" genuinely lives on a later line than the "On".
            return preg_match('/^on\b.{0,300}?\bwrote\s*:/is', $window) === 1;
        }

        // A single-line attribution with no "On": "Support <support@example.com> wrote:".
        // Anchored at both ends, so a sentence that merely mentions an address cannot match.
        return preg_match('/^.{0,120}<[^>]+@[^>]+>\s*wrote\s*:$/i', $line) === 1;
    }

    /**
     * Two or more of Outlook's `From: / Sent: / To: / Cc: / Subject:` within a few lines.
     *
     * Two, not one: a customer writing "Sent: yesterday" in a sentence should not truncate their
     * own message, and the header block never appears alone.
     *
     * @param  array<int, string>  $lines
     */
    private function headerBlockNear(array $lines, int $index): bool
    {
        $hits = 0;

        foreach (array_slice($lines, $index, 8) as $line) {
            if (preg_match('/^\s*(from|sent|to|cc|subject|date)\s*:\s*\S/i', $line) === 1) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    /**
     * From here to the end, is everything either quoted or blank?
     *
     * The guard that stops a customer who quotes one line mid-message from losing everything
     * they wrote underneath it.
     *
     * @param  array<int, string>  $lines
     */
    private function quotedToEnd(array $lines, int $index): bool
    {
        foreach (array_slice($lines, $index) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '>')) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * MIME scaffolding that should never have reached a body.
     *
     * Postmark hands us decoded `TextBody` and `HtmlBody`, so this should find nothing — it is
     * insurance for a malformed multipart that a provider passed through as text, which is the
     * shape the requirement names: `Content-Type:`, `boundary=`, `Content-Transfer-Encoding:`
     * and the base64 that follows. A ticket comment full of that is unreadable, and the file it
     * describes has already been stored properly by the attachment pipeline (P66).
     */
    private function stripMimeNoise(string $body): string
    {
        if (stripos($body, 'content-transfer-encoding') === false
            && stripos($body, 'content-disposition') === false
            && preg_match('/^--[-\w]{10,}\s*$/m', $body) !== 1) {
            return $body;
        }

        // A MIME boundary and everything to the end: once the parts begin, nothing below is prose.
        $body = (string) preg_replace('/^--[-\w]{10,}\s*$.*/ms', '', $body);

        // A header block plus the encoded payload under it.
        $body = (string) preg_replace(
            '/^Content-(Type|Disposition|Transfer-Encoding|ID)\s*:.*$(\R(^[^\r\n]*$)?)*?(\R^[A-Za-z0-9+\/=]{60,}$)*/mi',
            '',
            $body,
        );

        // Any long run of bare base64 left behind.
        return (string) preg_replace('/^(?:[A-Za-z0-9+\/=]{60,}\R){3,}/m', '', $body);
    }

    /** @return array<int, string> */
    private function quoteQueries(): array
    {
        return [
            // Gmail — `gmail_quote`, `gmail_quote_container`, `gmail_attr`.
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' gmail_quote ')]",
            "//*[contains(@class, 'gmail_quote_container')]",
            // Apple Mail, and the generic cite blockquote several clients emit.
            '//blockquote[@type="cite"]',
            // Outlook desktop and web.
            '//*[@id="divRplyFwdMsg"]',
            '//*[@id="appendonsend"]',
            "//*[contains(@class, 'OutlookMessageHeader')]",
            "//*[starts-with(@id, 'mail-editor-reference-message-container')]",
            // Yahoo.
            "//*[contains(@class, 'yahoo_quoted')]",
            "//*[starts-with(@id, 'yahoo_quoted')]",
            // Thunderbird's attribution line; its blockquote follows as a sibling.
            "//*[contains(@class, 'moz-cite-prefix')]",
            // Zimbra and several webmails.
            "//*[contains(@class, 'zmail_extra')]",
            "//*[@id='reply-intro']",
            "//*[contains(@class, 'protonmail_quote')]",
        ];
    }

    /**
     * A "… wrote:" line in the markup, with the quote as its SIBLING rather than its child.
     *
     * The shape Thunderbird and several webmails produce, and the one the container list above
     * cannot catch: there is no class to match, only a paragraph that says who wrote what.
     */
    private function removeAfterAttribution(DOMDocument $doc, DOMXPath $xpath): int
    {
        $nodes = $xpath->query('//p|//div');

        if ($nodes === false) {
            return 0;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = trim((string) preg_replace('/\s+/', ' ', $node->textContent));

            // Short, so a paragraph that merely CONTAINS the words is not mistaken for the
            // one-line attribution that always precedes a quote.
            if (mb_strlen($text) > 220 || $text === '') {
                continue;
            }

            if (preg_match('/^on\b.{0,200}?\bwrote\s*:$/is', $text) !== 1) {
                continue;
            }

            $this->removeFollowing($node);
            $this->remove($node);

            return 1;
        }

        return 0;
    }

    /** Everything after this node, at every level up to the body. */
    private function removeFollowing(DOMNode $node): void
    {
        $current = $node;

        while ($current !== null && $current->nodeName !== 'body') {
            while ($current->nextSibling !== null) {
                $this->remove($current->nextSibling);
            }

            $current = $current->parentNode;
        }
    }

    private function remove(DOMNode $node): void
    {
        $node->parentNode?->removeChild($node);
    }

    private function parse(string $html): ?DOMDocument
    {
        $doc = new DOMDocument;

        // The meta forces UTF-8: without it DOMDocument assumes ISO-8859-1 and mangles every
        // accented character in the customer's message.
        $wrapped = '<?xml encoding="UTF-8"><html><head><meta charset="utf-8"></head><body>'
            .$html.'</body></html>';

        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $doc : null;
    }

    private function innerHtml(DOMDocument $doc): string
    {
        $body = $doc->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $html = '';

        foreach ($body->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return trim($html);
    }

    /** Trailing blank lines and the runs of them a quoted reply leaves behind. */
    private function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}

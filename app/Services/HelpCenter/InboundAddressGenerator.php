<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterInbox;
use RuntimeException;

/**
 * The unguessable identifier behind an Inbox's inbound address
 * (docs/features/help-center.md §8).
 *
 * §8 sets four requirements and each one shapes the implementation:
 *
 *   "automatically generated"          — nobody is asked to choose one;
 *   "globally unique"                  — checked across the whole table, not per workspace,
 *                                        because mail arrives carrying this and nothing else
 *                                        (§20 rule 5);
 *   "not expose sequential database ids" — so it is random, never derived from the row;
 *   "difficult to guess"               — so it comes from random_bytes, not from mt_rand.
 *
 * The last of those is the security-relevant one: anybody who guesses a live identifier can
 * post mail into a stranger's Help Center. 8 characters of a 32-symbol alphabet is 40 bits,
 * which is a trillion addresses — enough that guessing is not a strategy, while staying short
 * enough to read down a phone to an IT administrator.
 */
class InboundAddressGenerator
{
    /**
     * Crockford-style base32: the digits and letters, minus the ones people misread.
     *
     * `I`, `L`, `O` and `U` are out — the first three because they are 1, 1 and 0 to anyone
     * copying an address by hand, and `U` because excluding vowels is what keeps a random
     * string from spelling something unfortunate on a customer's screen.
     */
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    private const LENGTH = 8;

    /** How many times to retry a collision before admitting something is wrong. */
    private const ATTEMPTS = 10;

    /**
     * A token no Inbox is using.
     *
     * The uniqueness check is `withoutGlobalScopes()` on purpose: the column is unique across
     * the TABLE, and a tenant-scoped check would happily hand this workspace a token another
     * workspace already holds — which the database would then reject, at the point where the
     * error is hardest to read.
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $candidate = $this->token();

            $taken = HelpCenterInbox::query()
                ->withoutGlobalScopes()
                ->where('inbound_id', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        /*
         * Ten collisions in a row against a 40-bit space is not bad luck — it means the
         * randomness is broken or the table is not what this thinks it is. Failing loudly beats
         * returning a token that is about to violate a unique index.
         */
        throw new RuntimeException('Could not generate a unique Help Center inbound identifier.');
    }

    /** One candidate. */
    private function token(): string
    {
        $alphabet = self::ALPHABET;
        $size = strlen($alphabet);
        $token = '';

        /*
         * random_int, not mt_rand: this is a credential in all but name, and it is drawn from
         * the CSPRNG for the same reason a password-reset token is.
         *
         * The modulo bias that usually argues against `% $size` does not arise here — 32
         * divides the 0-255 byte range exactly — but random_int is the clearer statement of
         * intent and needs no such argument.
         */
        for ($i = 0; $i < self::LENGTH; $i++) {
            $token .= $alphabet[random_int(0, $size - 1)];
        }

        return $token;
    }
}

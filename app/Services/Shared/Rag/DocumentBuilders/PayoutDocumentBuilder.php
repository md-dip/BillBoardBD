<?php

namespace App\Services\Shared\Rag\DocumentBuilders;

use App\Models\Payout;
use App\Services\Shared\Rag\DocumentDraft;

/**
 * Money the platform has actually sent an owner. Owner-only, pinned to the
 * account it was paid to.
 *
 * Kept separate from the earnings summaries on purpose: "what have I been paid"
 * and "what have I earned" are different questions with different answers, and
 * giving each its own document lets retrieval tell them apart.
 */
class PayoutDocumentBuilder implements DocumentBuilder
{
    public function sourceType(): string
    {
        return 'payout';
    }

    public function build(): iterable
    {
        foreach (Payout::query()->cursor() as $payout) {
            yield $this->forPayout($payout);
        }
    }

    public function forPayout(Payout $payout): DocumentDraft
    {
        $lines = [sprintf(
            'Payout #%d of BDT %s was sent to the billboard owner%s%s.',
            $payout->id,
            number_format((float) $payout->amount),
            $payout->paid_at ? ' on '.$payout->paid_at->toFormattedDateString() : '',
            $payout->method ? ' by '.$payout->method : '',
        )];

        if ($payout->reference) {
            $lines[] = sprintf('Its payment reference is %s.', $payout->reference);
        }

        if ($payout->note) {
            $lines[] = $payout->note;
        }

        return new DocumentDraft(
            sourceType: $this->sourceType(),
            sourceId: (string) $payout->id,
            title: sprintf('Payout #%d', $payout->id),
            content: implode(' ', $lines),
            visibleToUserId: $payout->owner_id,
            visibleToRole: 'owner',
        );
    }
}

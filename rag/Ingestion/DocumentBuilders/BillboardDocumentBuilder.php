<?php

namespace Rag\Ingestion\DocumentBuilders;

use App\Models\Billboard;
use Rag\Ingestion\DocumentDraft;

/**
 * Every publicly listed billboard, written as a description rather than a row.
 *
 * Shared: any signed-in actor may retrieve these, because a board on the public
 * map is public. Boards still in the listing review queue are excluded for the
 * same reason the API hides them - they are not reachable by URL either.
 */
class BillboardDocumentBuilder implements DocumentBuilder
{
    public function sourceType(): string
    {
        return 'billboard';
    }

    public function build(): iterable
    {
        foreach (Billboard::query()->where('listing_status', 'approved')->cursor() as $billboard) {
            yield new DocumentDraft(
                sourceType: $this->sourceType(),
                sourceId: (string) $billboard->id,
                title: $billboard->title,
                content: $this->describe($billboard),
                visibleToUserId: null,
                visibleToRole: 'all',
            );
        }
    }

    public function describe(Billboard $billboard): string
    {
        $lines = [];

        $lines[] = sprintf(
            '%s is a %s billboard at %s.',
            $billboard->title,
            $billboard->type,
            $billboard->address,
        );

        $lines[] = sprintf('Size: %s.', $billboard->size);

        $lines[] = $billboard->pricing_mode === 'monthly'
            ? sprintf('It is rented by the month at BDT %s per month.', number_format((float) $billboard->monthly_rate))
            : sprintf('It is rented by the day at BDT %s per day.', number_format((float) $billboard->daily_rate));

        if ((float) $billboard->rating > 0) {
            $lines[] = sprintf('Advertisers rate it %s out of 5.', rtrim(rtrim((string) $billboard->rating, '0'), '.'));
        }

        if ($billboard->permit_expiry_date) {
            $lines[] = $billboard->permit_expiry_date->isPast()
                ? sprintf('Its advertising permit expired on %s.', $billboard->permit_expiry_date->toFormattedDateString())
                : sprintf('Its advertising permit is valid until %s.', $billboard->permit_expiry_date->toFormattedDateString());
        }

        if ($billboard->description) {
            $lines[] = $billboard->description;
        }

        // Whether the dates a user wants are free is a live question that only
        // a query can answer, so this says nothing about availability - the
        // search_billboards tool exists for exactly that.
        $lines[] = sprintf('Billboard reference number %d.', $billboard->id);

        return implode(' ', $lines);
    }
}

<?php

namespace Rag\Ingestion\DocumentBuilders;

use Rag\Ingestion\DocumentDraft;

/**
 * The hand-written policy corpus in rag/policy/*.md - the only part of
 * the knowledge base that is prose rather than a rendered row.
 *
 * Audience comes from the filename: client-*.md is retrievable only by clients,
 * owner-*.md only by owners, anything else by both.
 *
 * These files describe RULES and never quote the numbers behind them. The
 * commission rate, advance percentage, hold length and listing fee are Settings
 * rows an admin can change at any time; baked into prose here they would be
 * embedded once and then confidently repeated back for months after the admin
 * moved them. The assistant reads live values through get_platform_settings
 * instead, so a document says "an advance is payable to submit a request" and
 * the tool supplies what the advance currently is.
 */
class PolicyDocumentBuilder implements DocumentBuilder
{
    public function __construct(
        private readonly string $path,
        private readonly int $chunkChars,
    ) {}

    public function sourceType(): string
    {
        return 'policy';
    }

    public function build(): iterable
    {
        if (! is_dir($this->path)) {
            return;
        }

        foreach (glob($this->path.'/*.md') ?: [] as $file) {
            $name = basename($file, '.md');
            $role = match (true) {
                str_starts_with($name, 'client-') => 'client',
                str_starts_with($name, 'owner-') => 'owner',
                default => 'all',
            };

            $markdown = (string) file_get_contents($file);
            $documentTitle = $this->documentTitle($markdown, $name);

            foreach ($this->sections($markdown) as $index => $section) {
                foreach ($this->split($section['body']) as $part => $body) {
                    // The document and section titles ride along in the text so
                    // a chunk reading "it is refunded automatically" still knows
                    // what "it" was - a small change that matters a lot on a
                    // corpus this size.
                    $heading = $documentTitle.' > '.$section['heading'];

                    yield new DocumentDraft(
                        sourceType: $this->sourceType(),
                        sourceId: "{$name}:{$index}:{$part}",
                        title: $heading,
                        content: $heading."\n\n".$body,
                        visibleToUserId: null,
                        visibleToRole: $role,
                    );
                }
            }
        }
    }

    /** The first "# " line, falling back to a readable form of the filename. */
    private function documentTitle(string $markdown, string $fallback): string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches)) {
            return trim($matches[1]);
        }

        return ucwords(str_replace('-', ' ', $fallback));
    }

    /**
     * Split at "## " headings. A section is the natural unit here - it is what
     * a person would quote as the answer to one question.
     *
     * @return array<int, array{heading: string, body: string}>
     */
    private function sections(string $markdown): array
    {
        // Drop the "# Title" line; it is already carried in the heading path.
        $body = preg_replace('/^#\s+.+$/m', '', $markdown) ?? $markdown;

        $parts = preg_split('/^##\s+(.+)$/m', trim($body), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $sections = [];

        // Anything before the first "##" is the document's own preamble.
        $preamble = trim(array_shift($parts) ?? '');
        if ($preamble !== '') {
            $sections[] = ['heading' => 'Overview', 'body' => $preamble];
        }

        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $sectionBody = trim($parts[$i + 1]);
            if ($sectionBody !== '') {
                $sections[] = ['heading' => trim($parts[$i]), 'body' => $sectionBody];
            }
        }

        return $sections;
    }

    /**
     * Split an over-long section on blank lines. Paragraph boundaries only - a
     * chunk cut mid-sentence embeds badly and reads worse when quoted back.
     *
     * @return array<int, string>
     */
    private function split(string $body): array
    {
        if (mb_strlen($body) <= $this->chunkChars) {
            return [$body];
        }

        $chunks = [];
        $current = '';

        foreach (preg_split('/\n\s*\n/', $body) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) > $this->chunkChars) {
                $chunks[] = $current;
                $current = $paragraph;

                continue;
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}

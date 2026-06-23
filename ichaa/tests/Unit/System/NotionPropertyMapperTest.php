<?php

namespace Tests\Unit\System;

use App\Domain\System\Services\NotionPropertyMapper;
use PHPUnit\Framework\TestCase;

class NotionPropertyMapperTest extends TestCase
{
    public function test_rich_text_preserves_intentional_edge_whitespace(): void
    {
        $mapper = new NotionPropertyMapper();

        $page = [
            'properties' => [
                'Content' => [
                    'type' => 'rich_text',
                    'rich_text' => [
                        ['plain_text' => 'Normal '],
                        ['plain_text' => 'bold'],
                        ['plain_text' => ' and '],
                        ['plain_text' => 'italic'],
                        ['plain_text' => '.'],
                    ],
                ],
            ],
        ];

        $this->assertSame('Normal bold and italic.', $mapper->richText($page, 'Content'));
    }

    public function test_rich_text_returns_null_when_chunks_are_only_whitespace(): void
    {
        $mapper = new NotionPropertyMapper();

        $page = [
            'properties' => [
                'Content' => [
                    'type' => 'rich_text',
                    'rich_text' => [
                        ['plain_text' => '   '],
                        ['plain_text' => "\n"],
                    ],
                ],
            ],
        ];

        $this->assertNull($mapper->richText($page, 'Content'));
    }
}

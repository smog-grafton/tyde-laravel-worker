<?php

namespace Tests\Unit;

use App\Support\TelegramFilenameParser;
use Tests\TestCase;

class TelegramFilenameParserTest extends TestCase
{
    public function test_it_extracts_title_episode_and_vj_from_worker_style_filenames(): void
    {
        $parsed = app(TelegramFilenameParser::class)->parse('28 YEARS LATER=1 VJ JOZZ UG.mkv');

        $this->assertSame('28 YEARS LATER', $parsed['title_guess']);
        $this->assertSame(1, $parsed['episode_guess']);
        $this->assertSame('JOZZ UG', $parsed['vj_guess']);
        $this->assertSame('mkv', $parsed['extension']);
    }
}

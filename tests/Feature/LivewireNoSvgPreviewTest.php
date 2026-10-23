<?php

namespace Tests\Feature;

use Livewire\Features\SupportFileUploads\FileNotPreviewableException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;

/**
 * An upload in progress is never previewed as SVG (docs/livechat.md §3 ΣΤ, review F7): Livewire would serve it from the
 * app's own origin, where an SVG's script runs.
 */
class LivewireNoSvgPreviewTest extends TestCase
{
    public function test_svg_is_not_a_preview_type(): void
    {
        $this->assertNotContains('svg', config('livewire.temporary_file_upload.preview_mimes'));
    }

    public function test_an_uploaded_svg_has_no_temporary_url_while_a_png_has_one(): void
    {
        $upload = fn (string $extension) => new class($extension) extends TemporaryUploadedFile
        {
            public function __construct(private string $extension) {}

            public function guessExtension(): string
            {
                return $this->extension;
            }
        };

        $this->assertTrue($upload('png')->isPreviewable());
        $this->assertFalse($upload('svg')->isPreviewable());
        $this->expectException(FileNotPreviewableException::class);
        $upload('svg')->temporaryUrl();
    }
}

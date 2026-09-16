<?php

namespace Tests\Unit;

use App\Support\ReturnEvidencePaths;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReturnEvidencePathsTest extends TestCase
{
    public function test_sanitizes_relative_image_and_video_paths(): void
    {
        $this->assertSame(
            ['uploads/returns/customer/photo.jpg', 'storage/uploads/returns/customer/proof.webp'],
            ReturnEvidencePaths::sanitizeImages([
                '/uploads/returns/customer/photo.jpg',
                'storage/uploads/returns/customer/proof.webp',
            ])
        );

        $this->assertSame(
            'uploads/returns/customer/video.mp4',
            ReturnEvidencePaths::sanitizeVideo('/uploads/returns/customer/video.mp4')
        );
    }

    public function test_rejects_remote_evidence_paths(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_EVIDENCE_PATH');

        ReturnEvidencePaths::sanitizeImages(['https://example.test/proof.jpg']);
    }

    public function test_rejects_traversal_evidence_paths(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_EVIDENCE_PATH');

        ReturnEvidencePaths::sanitizeVideo('../storage/app/.env.mp4');
    }

    public function test_rejects_wrong_media_extensions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_EVIDENCE_EXTENSION');

        ReturnEvidencePaths::sanitizeImages(['uploads/returns/customer/proof.php']);
    }

    public function test_owner_scoped_customer_evidence_accepts_matching_owner(): void
    {
        $this->assertSame(
            ['uploads/returns/user-1/proof.jpg'],
            ReturnEvidencePaths::sanitizeImagesForOwner(['uploads/returns/user-1/proof.jpg'], 'user-1')
        );

        $this->assertSame(
            'storage/uploads/returns/user-1/proof.mp4',
            ReturnEvidencePaths::sanitizeVideoForOwner('/storage/uploads/returns/user-1/proof.mp4', 'user-1')
        );
    }

    public function test_owner_scoped_customer_evidence_rejects_other_owner_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EVIDENCE_OWNER_MISMATCH');

        ReturnEvidencePaths::sanitizeImagesForOwner(['uploads/returns/user-2/proof.jpg'], 'user-1');
    }

    public function test_owner_scoped_shop_evidence_uses_shop_return_root(): void
    {
        $this->assertSame(
            ['uploads/shop_returns/store-1/proof.jpg'],
            ReturnEvidencePaths::sanitizeImagesForOwner(['uploads/shop_returns/store-1/proof.jpg'], 'store-1', ['shop_returns'])
        );
    }
}

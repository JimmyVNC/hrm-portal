<?php

use PHPUnit\Framework\TestCase;
use App\Application\AuthActions;

final class AuthActionsTest extends TestCase {
    public function testNormalizeHeaderValueRemovesBomAndExtraSpaces(): void {
        $value = "\xEF\xBB\xBF  Ma   NV ";
        $this->assertSame('MA NV', AuthActions::normalizeHeaderValue($value));
    }

    public function testResolveUploadFilePathRejectsTraversal(): void {
        $this->assertFalse(AuthActions::resolveUploadFilePath('../etc/passwd'));
    }
}

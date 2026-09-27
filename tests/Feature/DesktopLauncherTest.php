<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class DesktopLauncherTest extends TestCase
{
    public function test_external_links_open_in_default_browser_not_inside_webview(): void
    {
        $source = file_get_contents(base_path('scripts/desktop/Program.cs'));

        $this->assertStringContainsString('NavigationStarting +=', $source);
        $this->assertStringContainsString('NewWindowRequested +=', $source);
        $this->assertStringContainsString('IsExternalUrl(e.Uri)', $source);
        $this->assertStringContainsString('e.Cancel = true;', $source);
        $this->assertStringContainsString('OpenExternalUrl(e.Uri);', $source);
        $this->assertStringContainsString('new ProcessStartInfo(url) { UseShellExecute = true }', $source);
        $this->assertStringNotContainsString('NewWindowRequested += (_, e) => { e.Handled = true; view.CoreWebView2.Navigate(e.Uri); };', $source);
    }
}

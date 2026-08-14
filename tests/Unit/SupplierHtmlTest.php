<?php

namespace Tests\Unit;

use App\Support\SupplierHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hotel descriptions are HTML written by TBO and rendered, unescaped, inside a
 * logged-in session with a wallet attached. What matters here is both halves: the
 * formatting survives, and nothing executable does.
 */
class SupplierHtmlTest extends TestCase
{
    public function test_it_keeps_the_formatting_a_description_is_written_in(): void
    {
        $clean = SupplierHtml::clean(
            '<p><strong>Hotel Overview:</strong> Nestled in Makati.</p>'
            .'<p><ul><li>Ayala Museum</li><li>Rizal Park</li></ul></p>'
            .'<br/><b>Disclaimer notification.</b>'
        );

        $this->assertStringContainsString('<strong>Hotel Overview:</strong>', $clean);
        $this->assertStringContainsString('<li>Ayala Museum</li>', $clean);
        $this->assertStringContainsString('<b>Disclaimer notification.</b>', $clean);
    }

    /**
     * A <ul> is not valid inside a <p>, so lifting it out where it belongs strands an
     * empty paragraph that would render as an unexplained gap.
     */
    public function test_husks_left_by_invalid_nesting_are_dropped(): void
    {
        $this->assertSame('<p>real</p>', SupplierHtml::clean('<p></p><p>real</p><p>   </p>'));
        $this->assertStringNotContainsString('<p></p>', SupplierHtml::clean('<p><ul><li>a</li></ul></p>'));
    }

    public function test_a_deliberate_line_break_is_not_mistaken_for_a_husk(): void
    {
        $this->assertSame('<p>line<br>break</p>', SupplierHtml::clean('<p>line<br>break</p>'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function payloads(): array
    {
        return [
            'script element' => ['<p>ok</p><script>alert(document.cookie)</script>', 'alert'],
            'event handler' => ['<p onclick="steal()">ok</p>', 'steal'],
            // An attribute containing ">" is what defeats the regex version of this.
            'attribute holding a bracket' => ['<p title="a>b" onmouseover="x()">ok</p>', 'onmouseover'],
            'image error handler' => ['<img src=x onerror="alert(1)">ok', 'onerror'],
            'iframe' => ['<p>ok</p><iframe src="//evil.test"></iframe>', 'evil.test'],
            'style block' => ['<style>body{display:none}</style><p>ok</p>', 'display:none'],
            'svg wrapped script' => ['<svg><script>alert(1)</script></svg><p>ok</p>', 'alert'],
            'markup hidden in a comment' => ['<p>ok</p><!-- <script>x()</script> -->', 'script'],
            'anchor href' => ['<p>see <a href="https://evil.test">this</a></p>', 'evil.test'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_it_removes_what_must_never_reach_the_page(string $input, string $forbidden): void
    {
        $clean = (string) SupplierHtml::clean($input);

        $this->assertStringNotContainsString($forbidden, $clean);
        $this->assertStringNotContainsString('<', str_replace(
            ['<p>', '</p>', '<br>', '<b>', '</b>', '<strong>', '</strong>'],
            '',
            $clean,
        ), 'no tag outside the allow-list survives');
    }

    /**
     * Dropping a tag must not drop the words it wrapped — a link's text is still
     * part of the sentence around it.
     */
    public function test_unwrapping_keeps_the_text(): void
    {
        $this->assertSame('<p>see this now</p>', SupplierHtml::clean('<p>see <a href="https://x.test">this</a> now</p>'));
    }

    public function test_nothing_to_clean_is_null_rather_than_an_empty_string(): void
    {
        $this->assertNull(SupplierHtml::clean(null));
        $this->assertNull(SupplierHtml::clean('   '));
        $this->assertNull(SupplierHtml::clean('<p></p>'));
        $this->assertNull(SupplierHtml::clean('<script>alert(1)</script>'));
    }

    public function test_plain_text_passes_through(): void
    {
        $this->assertSame('Just a sentence.', SupplierHtml::clean('Just a sentence.'));
    }

    /**
     * Accents and currency symbols are common in this copy and must survive the
     * round trip through libxml, which defaults to Latin-1.
     */
    public function test_non_ascii_text_survives(): void
    {
        $this->assertSame('<p>Makati’s café — ₱1,200</p>', SupplierHtml::clean('<p>Makati’s café — ₱1,200</p>'));
    }
}

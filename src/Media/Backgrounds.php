<?php
declare( strict_types=1 );

namespace RCRocket\Media;

use RCRocket\Frontend\HtmlPipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lazy loading for Divi background images.
 *
 * Section, row, column and module backgrounds live in Divi's generated CSS,
 * where loading="lazy" cannot reach them, so a long Divi page downloads
 * every background on it before the visitor has scrolled at all. This holds
 * them back per section: a stylesheet rule switches background images off
 * inside sections that have not come near the viewport, and a few lines of
 * script lift the rule section by section as the visitor scrolls.
 *
 * The first sections in the document are never held back; that is where the
 * header and hero are. Without JavaScript nothing is held back at all: the
 * rule only applies once a script has marked the document. Gradients are
 * background images too, so they arrive with their section; offscreen, that
 * is invisible.
 */
final class Backgrounds {

	/** Sections from the top of the document that always load at once. */
	public const EAGER_SECTIONS = 3;

	public function rewrite( string $html, int $eager = self::EAGER_SECTIONS ): string {
		if ( ! str_contains( $html, 'et_pb_section' ) ) {
			return $html;
		}

		$seen  = 0;
		$total = 0;

		$html = (string) preg_replace_callback(
			'#(<(?:div|section)\b[^>]*?\bclass\s*=\s*")([^"]*\bet_pb_section\b[^"]*)(")#i',
			static function ( array $m ) use ( &$seen, &$total, $eager ): string {
				++$total;

				if ( ++$seen > $eager ) {
					return $m[0];
				}

				return $m[1] . $m[2] . ' rcr-bg-in' . $m[3];
			},
			$html
		);

		// A short page has nothing below the eager sections to hold back.
		if ( $total <= $eager ) {
			return $html;
		}

		$targets = '.et_pb_row,.et_pb_column,.et_pb_module,.et_parallax_bg,.et_pb_slide,.et_pb_slides,.et_pb_with_background';

		$head = '<script id="rcr-bg-lazy-flag">document.documentElement.classList.add("rcr-bgl")</script>'
			. '<style id="rcr-bg-lazy-css">.rcr-bgl .et_pb_section:not(.rcr-bg-in),.rcr-bgl .et_pb_section:not(.rcr-bg-in) :is(' . $targets . '){background-image:none!important}</style>';

		$foot = <<<HTML
<script id="rcr-bg-lazy">
(function () {
  var sections = [].slice.call(document.querySelectorAll('.et_pb_section:not(.rcr-bg-in)'));
  function show(s) { s.classList.add('rcr-bg-in'); }
  if (!('IntersectionObserver' in window)) { sections.forEach(show); return; }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) { show(e.target); io.unobserve(e.target); } });
  }, { rootMargin: '600px 0px' });
  sections.forEach(function (s) { io.observe(s); });
  addEventListener('beforeprint', function () { sections.forEach(show); });
})();
</script>
HTML;

		return HtmlPipeline::before_body_end( HtmlPipeline::after_head_start( $html, $head ), $foot );
	}
}

<?php
/** Real markup, as Divi actually emits it. */

// Divi 4 background video: WordPress's video shortcode inside Divi's span.
// Note there is no `muted` attribute — Divi applies that from JavaScript.
const DIVI4_VIDEO = <<<'HTML'
<div class="et_pb_section et_pb_section_0 et_section_regular et_pb_section_video et_pb_preload">
<span class="et_pb_section_video_bg">
<div class="wp-video" style="width: 1920px;">
<video class="wp-video-shortcode" id="video-12-1" width="1920" height="1080" loop="1" autoplay="1" preload="metadata" controls="controls">
<source type="video/mp4" src="https://example.com/wp-content/uploads/2026/01/hero.mp4?_=1" />
</video>
</div>
</span>
<div class="et_pb_row"><h1>Concrete Leveling</h1></div>
</div>
HTML;

// Divi 5 background video.
const DIVI5_VIDEO = <<<'HTML'
<section class="divi-section" data-module="section">
<div class="divi-background-video">
<video autoplay muted loop playsinline preload="auto">
<source src="https://example.com/wp-content/uploads/2026/02/loop.mp4" type="video/mp4">
</video>
</div>
<h1>Your whole marketing team.</h1>
</section>
HTML;

// A video the visitor is meant to control. Must never be gated.
const CONTENT_VIDEO = <<<'HTML'
<video controls preload="metadata" poster="https://example.com/p.jpg">
<source src="https://example.com/testimonial.mp4" type="video/mp4">
</video>
HTML;

const VIMEO_BACKGROUND = <<<'HTML'
<div class="dsm_video_background">
<iframe src="https://player.vimeo.com/video/1210835154?background=1&amp;autoplay=1&amp;loop=1&amp;muted=1" width="1920" height="1080" frameborder="0" allow="autoplay"></iframe>
</div>
HTML;

const VIMEO_CONTENT = <<<'HTML'
<iframe src="https://player.vimeo.com/video/76979871" width="640" height="360" frameborder="0" allowfullscreen></iframe>
HTML;

const IMAGE_MARKUP = <<<'HTML'
<body>
<img src="https://example.com/wp-content/uploads/2026/01/hero.jpg" alt="Hero">
<img src="https://example.com/wp-content/uploads/2026/01/second.jpg" alt="Second">
<img src="https://example.com/wp-content/uploads/2026/01/third.jpg" alt="Third" class="skip-lazy">
<img src="https://example.com/wp-content/uploads/2026/01/fourth.jpg" alt="Fourth" width="800" height="600">
<img src="https://example.com/logo.svg" alt="Logo" loading="eager">
<iframe src="https://maps.google.com/x"></iframe>
</body>
HTML;

const SCRIPT_MARKUP = <<<'HTML'
<body>
<script src="https://example.com/wp-includes/js/jquery/jquery.min.js?ver=3.7.1"></script>
<script src="https://example.com/wp-content/themes/Divi/js/scripts.min.js" id="divi-custom-script-js"></script>
<script src="https://connect.facebook.net/en_US/fbevents.js"></script>
<script type="application/ld+json">{"@type":"Organization"}</script>
<script id="rcr-beacon">console.log('ours');</script>
<script>var inline = 1;</script>
</body>
HTML;

// A Divi 4 section shortcode that carries both a video and its fallback image.
const DIVI4_SHORTCODE = '[et_pb_section fb_built="1" background_video_mp4="https://example.com/wp-content/uploads/2026/01/hero.mp4" background_image="https://example.com/wp-content/uploads/2026/01/hero-fallback.jpg" _builder_version="4.27.4"][et_pb_row][et_pb_column type="4_4"][et_pb_gallery gallery_ids="1,2"][/et_pb_gallery][et_pb_toggle title="FAQ"][/et_pb_toggle][/et_pb_column][/et_pb_row][/et_pb_section]';

// Divi 5 saves layouts as block markup. Trimmed from a live Divi 5.14 home
// page: desktop has a video and an image, tablet an image only, and phone
// switches the video off with an empty string.
const DIVI5_BLOCKS = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"video":{"mp4":"https://example.com/wp-content/uploads/2026/07/hero.mp4"},"gradient":{"enabled":"off"},"image":{"url":"https://example.com/wp-content/uploads/2026/09/hero-still.webp"}}},"tablet":{"value":{"image":{"url":"https://example.com/wp-content/uploads/2026/09/hero-still.webp"}}},"phone":{"value":{"video":{"mp4":""}}}},"sizing":{"desktop":{"value":{"minHeight":"90vh"}}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/row {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#000"}}}}}} --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"video":{"webm":"https://example.com/wp-content/uploads/2026/07/second.webm"}}},"tablet":{"value":{"image":{"url":"https://example.com/wp-content/uploads/2026/07/second-tablet.jpg"}}}}}}} --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

// How Divi 5 renders that background video: data-src, filled in by Divi's script.
const DIVI5_LIVE_VIDEO = <<<'HTML'
<div class="et_pb_section_0 et_pb_section et_section_regular et_flex_section et-pb-has-background-video et_pb_preload hero"><span class="et-pb-background-video"><video autoplay loop playsinline muted><source type="video/mp4" data-src="https://example.com/wp-content/uploads/2026/07/hero.mp4" /></video></span><div class="et_pb_row_0 et_pb_row"><h1>Headline</h1></div></div>
HTML;

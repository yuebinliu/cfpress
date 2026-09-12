=== Loupely Canvas Lite ===
Contributors: loupely
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: one-column, custom-logo, custom-menu, featured-images, full-width-template, translation-ready, editor-style, threaded-comments, block-styles

A minimal editorial theme with a clean default design and a one-switch blank canvas for people who write their own HTML and CSS.

== Description ==

Loupely Canvas Lite gives you a finished, minimal site out of the box: a header with your logo or site title and a navigation menu, a footer, and readable typography set in Lora and Inter on a calm sage palette. Posts, archives, search, and the 404 page are all styled, so you can publish straight away.

When you would rather build everything yourself, turn on Start from a blank canvas in the Customizer. That removes the design, the fonts, the default header and footer, and the content width, leaving a full-width passthrough where the only thing on the page is the HTML you write. Set your own header and footer in the Customizer and you have a clean slate.

What it does:

* Ships a cohesive default design with a styled header, navigation, footer, and typography.
* Renders posts, archives, search, and a 404 page with clean, readable templates.
* Adds per page controls to hide the title, render a page full width, or add a body class.
* Turns into a full-width passthrough with one switch, for hand-coded pages.
* Lets you set a global header and footer in the Customizer that replace the defaults.

No page builder. No build step. The two bundled typefaces are the only assets.

This is the lite edition. The full free version adds head and body code injection, per page header and footer control, starter content, and a find and replace tool that works inside any HTML box. It is also free, at loupelycanvas.com.

== Installation ==

1. In wp-admin go to Appearance, Themes, Add New, search for Loupely Canvas Lite, and install it. Or upload the zip under Add New, Upload Theme.
2. Activate it.
3. Set your logo under Appearance, Customize, and your navigation under Appearance, Menus.
4. To build raw pages instead, turn on Appearance, Customize, Canvas, Start from a blank canvas.

== Frequently Asked Questions ==

= How do I get the bare, full-width canvas? =

Turn on Appearance, Customize, Canvas, Start from a blank canvas. The default design, fonts, and header and footer drop away, and your pasted HTML renders full width. To strip just one page rather than the whole site, open that page and check Full width under Page settings.

= Which fonts does it use, and are they loaded from elsewhere? =

Lora for headings and Inter for body text. Both are bundled with the theme and served from your own site, so nothing is requested from a third party. They load only in the default design, not in the blank canvas.

= Will my scripts be stripped from the header and footer? =

The Customizer header and footer allow markup but not scripts, which keeps Lite simple and safe. For raw scripts in your header or footer, use the full free version at loupelycanvas.com.

= How do I set up a blog? =

There is nothing to configure. Posts, category and tag archives, search results, and the 404 page are styled automatically. For a static homepage with posts on a separate page, set both under Settings, Reading.

= What is the difference between Lite and the full version? =

Lite is the design plus the blank canvas switch and a few per page controls. The full free version adds head and body code injection, per page header and footer overrides, starter content, and a find and replace tool for your HTML boxes. The full version is also free, distributed through loupelycanvas.com.

== Changelog ==

= 1.2.3 =
* Reworded the Themes screen notice to open with "You are using Loupely Canvas Lite."

= 1.2.2 =
* Updated the Themes screen notice: the first appearance mentions starter content; subsequent appearances drop that line since users already have content by then. Corrected "HTML boxes" to "custom code blocks" throughout the notice copy.

= 1.2.1 =
* Added align-wide theme support so the block editor offers wide and full alignment options.
* Added a dismissible Themes screen notice pointing to the full free theme at loupelycanvas.com. The notice re-appears every 90 days, up to 4 times, then stops.

= 1.2.0 =
* New default design. The theme now ships a finished, minimal editorial look: a header with your logo or site title and a navigation menu, a footer with a footer menu and copyright, and readable typography in Lora and Inter on the sage palette, with styled posts, pages, comments, forms, and pagination.
* Added Start from a blank canvas, a Customizer switch that removes the design, fonts, and default chrome and returns the theme to a full-width passthrough you build yourself.
* Added per page controls to hide the title, render a page full width, and add a body class.
* Added custom logo support and Primary and Footer navigation menus.
* Bundled the Lora and Inter typefaces locally, served from your own site.
* Renamed the internal prefix to lclite for uniqueness.

= 1.1.2 =
* Reply links on threaded comments now work. The core comment reply script loads on single posts when threaded comments are turned on.
* Added the pingback link in the head on single posts that accept pings.
* Removed a small inline style from the page head; the same rule already lives in the stylesheet.

= 1.1.1 =
* Added featured image support, so the blog and single posts can show a post's featured image.

= 1.1.0 =
* Added full blog support: post, archive, search, and 404 templates, a single post view with comments, and a blog index.

= 1.0.0 =
* Initial release. Full width passthrough rendering and a Customizer based global header and footer.

== Copyright ==

Loupely Canvas Lite is distributed under the terms of the GNU GPL version 2 or later.

== Resources ==

Lora typeface, copyright the Lora Project Authors, SIL Open Font License v1.1.
Source: https://github.com/google/fonts/tree/main/ofl/lora
License copy: assets/fonts/Lora-OFL.txt

Inter typeface, copyright the Inter Project Authors, SIL Open Font License v1.1.
Source: https://github.com/google/fonts/tree/main/ofl/inter
License copy: assets/fonts/Inter-OFL.txt

<?php
/**
 * Exit Intent & Idle Modal Shortcode
 *
 * @wordpress
 *
 * GOAL:
 * - Show a simple popup based on three common behaviors: Exit intent (when a desktop user moves
 *   the mouse toward the browser’s top bar) Idle time (after a visitor hasn’t
 *   moved/typed/scrolled for N seconds) After scrolling a specified amount down the page.
 * - Keep everything self‑contained: the shortcode prints its own minimal CSS and JS inline.
 * - Work nicely with normal page content, including Gravity Forms shortcodes inside the popup.
 *
 * REQUIREMENTS:
 * - WordPress and (optionally) Gravity Forms if you want to show a form in the popup.
 * - No extra plugins or theme edits required. The shortcode includes its own CSS/JS once per page.
 * - Exit intent is a desktop behavior only; on mobile/tablet use the idle trigger.
 *
 * # How to use it (quick start)
 *
 * # 1) Paste this in your page/post content
 *
 *     [gosmartmodal width="700" id="welcome-offer"]
 *     <div open_on="exit" position="top" dismiss="10s">
 *       <div style="text-align:center;">
 *         <h2>Here's a gift</h2>
 *         <p>Before you go, enjoy 10% off with code <strong class="gosmartmodalcoupon">GRAVITYOPS10</strong>.</p>
 *         <p><a href="#pricing" class="gosmartmodalbutton">View Plans</a></p>
 *       </div>
 *     </div>
 *     <div open_on="idle:3" position="center" dismiss="10s">
 *       <p>Have a question? Ask us anything!</p>
 *       [gravityform id="12" title="false"]
 *     </div>
 *     [/gosmartmodal]
 *
 * # 2) What happens
 *
 * - The first popup appears on exit intent.
 * - The second appears after 3 seconds of inactivity.
 * - Clicking “View Plans” closes the popup and scrolls to the #pricing section.
 *
 * # What each part means
 *
 * # Shortcode wrapper: [gosmartmodal width="700" id="welcome-offer"] ...
 *
 * # [/gosmartmodal]
 *
 * - **width**: limits the popup’s max width. Number only → pixels (e.g., 700 → 700px) CSS
 *   length value → 70vw, 90%, 40rem, 32em, 720px Default: 700px Note: calc() not supported.
 * - **id**: unique identifier used to scope dismissal memory. Letters, numbers, underscore, hyphen
 *   Default: auto‑generated Reusing id shares dismissal memory.
 *
 * # Inner blocks: each <div> controls one popup
 *
 * - **open_on** (required): when to open the popup. "exit" or "exit-intent" "idle:N" → N seconds
 *   inactivity (decimals allowed) "scroll:X%" → X percent down page "scroll:Ypx" → Y pixels
 *   down page
 * - **position**: top, center (default), bottom
 * - **dismiss**: auto‑close timer "10s", "2.5s", "5000ms", "10000" 0 disables
 * - **dismiss_for**: how long the popup stays dismissed "10s", "2.5s", "30m", "1h", "5000ms", bare
 *   ms 0 disables Default: 30m
 *
 * # Full Attribute Reference (Quick Lookup)
 *
 * # [gosmartmodal] wrapper attributes
 *
 * - **width**: integer px or CSS length. Default: 700.
 * - **id**: unique slug used for dismissal memory.
 *
 * # Inner <div> attributes
 *
 * - **open_on**: exit, exit-intent, idle:N, scroll:X% or Ypx
 * - **position**: top | center | bottom
 * - **dismiss**: seconds or milliseconds
 * - **dismiss_for**: memory window; default 30m
 *
 * USAGE:
 * # Exit intent with bottom position
 *
 *     <div open_on="exit" position="bottom">...</div>
 *
 * # Idle after 2.5s, auto‑close after 6s
 *
 *     <div open_on="idle:2.5" position="top" dismiss="6s">...</div>
 *
 * # Idle after 8s, ms-based dismiss
 *
 *     <div open_on="idle:8" dismiss="8000">...</div>
 *
 * # Scroll 60%
 *
 *     <div open_on="scroll:60%">...</div>
 *
 * # Scroll 800px
 *
 *     <div open_on="scroll:800px">...</div>
 *
 * # Behavior notes
 *
 * - **Dismissal memory**: a popup won’t open again during its dismiss_for window.
 * - **Accessibility**: role="dialog", aria-modal="true", ESC to close, focus sent to close button.
 * - **.gosmartmodalbutton** closes popup first, then navigates; hash links smooth‑scroll.
 *
 * # Styling and classes
 *
 * - `.gosmartmodalcoupon` — bold coupon code helper
 * - `.gosmartmodalbutton` — closes popup before navigating
 * - Prefixed classes avoid theme conflicts
 *
 * # Accessibility & Behavior
 *
 * - Popups include ARIA roles.
 * - Focus moves to the close button.
 * - Clicking outside closes popup.
 *
 * # Helpful tips
 *
 * - Exit intent: move cursor toward top browser chrome.
 * - To re-trigger a dismissed popup: wait 30m or clear site data.
 * - Anchor links like #pricing smooth‑scroll after closing.
 * - For Gravity Forms inside popup: forms activate once visible.
 *
 * # What this does not do
 *
 * - Cannot override browser Back/Close behaviors (security rule).
 * - Exit popup won’t fire on mobile; use idle trigger.
 *
 * # Need to customize?
 *
 * - Timings, positions, and small design tweaks can be adjusted.
 * - Any HTML allowed inside popup (images, headings, Gravity Forms).
 *
 * FEATURES:
 * - Three triggers you can use together or separately: open_on="exit" → show on exit intent
 *   (desktop browsers) open_on="idle:3" → show after 3 seconds of inactivity
 *   open_on="scroll:50%" → show after the visitor has scrolled 50% of the page
 * - Choose where the popup sits: position="top", "center" (default), or "bottom".
 * - Optional auto‑close timer: dismiss="10s" closes it after 10 seconds.
 * - A ready‑made Close button (×), backdrop click to close, and ESC key support.
 * - Any link inside the popup with class "gosmartmodalbutton" will close the popup first, then
 *   continue to that link. If it’s a #section link, the page will smooth‑scroll there.
 * - Friendly with Gravity Forms: you can place a [gravityform] shortcode inside the popup.
 * - Respectful behavior: if a visitor closed a popup, it won’t pop again for its dismiss_for
 *   duration (30 minutes by default).
 */

// Security: don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bl_register_gosmartmodal_shortcode' ) ) {
	/**
	 * Register the [gosmartmodal] shortcode.
	 */
	function bl_register_gosmartmodal_shortcode() {
		add_shortcode( 'gosmartmodal', 'bl_gosmartmodal_shortcode' );
	}
	add_action( 'init', 'bl_register_gosmartmodal_shortcode' );
}

if ( ! function_exists( 'bl_gosmartmodal_shortcode' ) ) {
	/**
	 * Render the [gosmartmodal] shortcode.
	 *
	 * @param array       $atts    Shortcode attributes (width, id).
	 * @param string|null $content Shortcode inner content containing <div open_on="..."> sections.
	 * @return string
	 */
	function bl_gosmartmodal_shortcode( $atts, $content = null ) {
		// Defaults.
		$atts = shortcode_atts(
			[
				'width' => '700',
				'id'    => uniqid( 'gosm_' ),
			],
			$atts,
			'gosmartmodal'
		);

		$base_id  = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $atts['id'] );
		$width_in = is_string( $atts['width'] ) ? trim( $atts['width'] ) : '700';

		// Normalize width: allow numbers (assume px) or any CSS length keyword.
		if ( preg_match( '/^\d+(?:\.\d+)?$/', $width_in ) ) {
			$width_css = $width_in . 'px';
		} else {
			// Very light sanitization of CSS value.
			$width_css = preg_replace( '/[^\w\.%\-\s]/', '', $width_in );
		}

		// Process nested shortcodes (e.g., [gravityform]) inside content.
		$content = do_shortcode( (string) $content );

		// Extract only sections that declare open_on="..." using DOM (handles nested DIVs).
		$sections = [];
		if ( is_string( $content ) && '' !== $content ) {
			$html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
			        . '<div id="gosm-wrapper">' . $content . '</div>'
			        . '</body></html>';

			$dom = new DOMDocument();
			// Suppress warnings for HTML5 tags/self-closing mismatches common in WP content.
			$prev = libxml_use_internal_errors( true );
			$dom->loadHTML( $html );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );

			$xpath = new DOMXPath( $dom );
			$nodes = $xpath->query( '//*[@id="gosm-wrapper"]//div[@open_on and not(ancestor::div[@open_on])]' );
			if ( $nodes instanceof DOMNodeList ) {
				foreach ( $nodes as $node ) {
					if ( ! ( $node instanceof DOMElement ) ) {
						continue;
					}
					$open_on     = trim( (string) $node->getAttribute( 'open_on' ) );
					$position    = strtolower( trim( (string) $node->getAttribute( 'position' ) ) );
					$dismiss     = trim( (string) $node->getAttribute( 'dismiss' ) );
					$dismiss_for = trim( (string) $node->getAttribute( 'dismiss_for' ) );

					// Determine trigger type and optional parameters (idle seconds or scroll threshold).
					$trigger       = 'manual';
					$idle_seconds  = 0;
					$scroll_amount = 0;
					$scroll_unit   = '%'; // default to percent if omitted
					if ( preg_match( '/^idle\s*:\s*(\d+(?:\.\d+)?)$/i', $open_on, $om ) ) {
						$trigger      = 'idle';
						$idle_seconds = (float) $om[1];
					} elseif ( preg_match( '/^scroll\s*:\s*(\d+(?:\.\d+)?)(%|px)?$/i', $open_on, $sm ) ) {
						$trigger       = 'scroll';
						$scroll_amount = (float) $sm[1];
						$scroll_unit   = isset( $sm[2] ) && in_array( strtolower( $sm[2] ), [ '%', 'px' ], true ) ? strtolower( $sm[2] ) : '%';
					} elseif ( strtolower( $open_on ) === 'exit' || strtolower( $open_on ) === 'exit-intent' ) {
						$trigger = 'exit';
					}

					// Parse dismiss duration into milliseconds (supports `10s` or `10000ms` or plain ms number).
					$dismiss_ms = 0;
					if ( '' !== $dismiss ) {
						if ( preg_match( '/^(\d+(?:\.\d+)?)\s*s$/i', $dismiss, $dm ) ) {
							$dismiss_ms = (int) floor( (float) $dm[1] * 1000 );
						} elseif ( preg_match( '/^(\d+)\s*ms$/i', $dismiss, $dm ) ) {
							$dismiss_ms = (int) $dm[1];
						} elseif ( preg_match( '/^\d+$/', $dismiss ) ) {
							$dismiss_ms = (int) $dismiss;
						}
					}

					// Parse dismiss_for into milliseconds (controls localStorage memory window per popup).
					// Default 30 minutes when missing/invalid; 0 disables memory.
					$dismiss_for_ms = 30 * 60 * 1000;
					if ( '' !== $dismiss_for ) {
						if ( '0' === $dismiss_for ) {
							$dismiss_for_ms = 0;
						} elseif ( preg_match( '/^(\d+(?:\.\d+)?)\s*s$/i', $dismiss_for, $m ) ) {
							// Match number followed by 's' (seconds) - e.g. "10s", "2.5s"
							$dismiss_for_ms = (int) floor( (float) $m[1] * 1000 );
						} elseif ( preg_match( '/^(\d+(?:\.\d+)?)\s*m$/i', $dismiss_for, $m ) ) {
							// Match number followed by 'm' (minutes) - e.g. "30m", "0.5m"
							$dismiss_for_ms = (int) floor( (float) $m[1] * 60 * 1000 );
						} elseif ( preg_match( '/^(\d+(?:\.\d+)?)\s*h$/i', $dismiss_for, $m ) ) {
							// Match number followed by 'h' (hours) - e.g. "1h", "1.5h"
							$dismiss_for_ms = (int) floor( (float) $m[1] * 60 * 60 * 1000 );
						} elseif ( preg_match( '/^(\d+(?:\.\d+)?)\s*ms$/i', $dismiss_for, $m ) ) {
							// Match number followed by 'ms' (milliseconds) - e.g. "5000ms"
							$dismiss_for_ms = (int) floor( (float) $m[1] );
						} elseif ( preg_match( '/^\d+$/', $dismiss_for ) ) {
							// Match bare number (milliseconds) - e.g. "1800000"
							$dismiss_for_ms = (int) $dismiss_for;
						}
					}

					// Sanitize descendant elements: remove inline event handlers and unsafe protocols in href/src.
					// Do not strip <script> tags to avoid breaking plugins (e.g., Gravity Forms) that emit inline init scripts.
					$descendants = [];
					foreach ( $node->getElementsByTagName( '*' ) as $el ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$descendants[] = $el;
					}
					foreach ( $descendants as $el ) {
						if ( ! ( $el instanceof DOMElement ) ) {
							continue;
						}
						// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$tag = strtolower( $el->tagName );
						if ( 'script' === $tag ) {
							continue; // keep scripts intact
						}
						if ( $el->hasAttributes() ) {
							$to_remove = [];
							foreach ( $el->attributes as $attr ) {
								$name = strtolower( (string) $attr->nodeName );
								if ( 0 === strpos( $name, 'on' ) ) {
									$to_remove[] = $attr->nodeName; // remove inline event handlers
									continue;
								}
								if ( in_array( $name, [ 'href', 'src', 'xlink:href' ], true ) ) {
									$val = trim( (string) $attr->nodeValue );
									if ( preg_match( '/^\s*(javascript:|data:)/i', $val ) ) {
										if ( 'href' === $name || 'xlink:href' === $name ) {
											$el->setAttribute( $attr->nodeName, '#' );
										} else {
											$to_remove[] = $attr->nodeName;
										}
									}
								}
							}
							// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							foreach ( $to_remove as $an ) {
								$el->removeAttribute( $an );
							}
						}
					}

					// Inner HTML of this node (after sanitization).
					$inner_html = '';
					foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$inner_html .= $dom->saveHTML( $child );
					}

					$sections[] = [
						'trigger'        => $trigger,
						'idle_seconds'   => $idle_seconds,
						'scroll_amount'  => $scroll_amount,
						'scroll_unit'    => $scroll_unit,
						'position'       => in_array( $position, [ 'top', 'center', 'bottom' ], true ) ? $position : 'center',
						'dismiss_ms'     => $dismiss_ms,
						'dismiss_for_ms' => max( 0, (int) $dismiss_for_ms ),
						'html'           => $inner_html,
					];
				}
			}
		}

		if ( empty( $sections ) ) {
			return '';
		}

		// Output buffers for HTML + optional inline assets (CSS/JS only once per page).
		$out = [];

		// Inline CSS/JS only once.
		static $gosmartmodal_assets_printed = false;
		if ( ! $gosmartmodal_assets_printed ) {
			$gosmartmodal_assets_printed = true;
			$out[]                       = <<<'GOSM_CSS'
<style id="gosmartmodal-inline-styles">
/* gosmartmodal basic styles */
.gosmartmodal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;z-index:999999;box-sizing:border-box;padding:4vh 16px;}
.gosmartmodal-overlay.is-open{display:flex;}
.gosmartmodal-overlay.pos-center{align-items:center;justify-content:center;}
.gosmartmodal-overlay.pos-top{align-items:flex-start;justify-content:center;}
.gosmartmodal-overlay.pos-bottom{align-items:flex-end;justify-content:center;}
.gosmartmodal-container{position:relative;background:#fff;color:#111;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.25);width:100%;max-height:88vh;overflow:auto;padding:24px;box-sizing:border-box;}
.gosmartmodal-close {
    position: absolute;
    top: 8px;
    right: 10px;
    width: 32px;          /* make it a clear target */
    height: 32px;
    display: flex;        /* center the X */
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
    z-index: 2;           /* ensure it sits above other content */
    font-size: 24px;
    line-height: 1;
}
.gosmartmodal-close:hover{color:#111;}
.gosmartmodal-close::before {
    content: "×";
}
.gosmartmodal-backdrop{position:absolute;inset:0;}
.gosmartmodal-content{position:relative;z-index:1;}
.gosmartmodalcoupon{font-weight:700;}
.gosmartmodalbutton{display:inline-block;background:#2c7be5;color:#fff !important;padding:10px 16px;border-radius:4px;}
.gosmartmodalbutton:hover{background:#1a68d1;}
</style>
GOSM_CSS;

			$out[] = <<<'GOSM_JS'
<script id="gosmartmodal-inline-script">
(function(){"use strict";
    let exitHandlerInstalled=false; const exitOverlays=[]; const idleControllers=[];

    // localStorage-based dismissal (30-minute throttle)
    const store = {
        key: function(id, reason){ return 'gosm:'+id+':'+reason; },
        setUntil: function(id, reason, msFromNow){
            try{
                const untilISO = new Date(Date.now() + msFromNow).toISOString();
                localStorage.setItem(store.key(id, reason), untilISO);
            }catch(e){}
        },
        until: function(id, reason){
            try{
                const v = localStorage.getItem(store.key(id, reason));
                return v ? Date.parse(v) : 0;
            }catch(e){ return 0; }
        },
        isDismissedNow: function(id, reason){
            const u = store.until(id, reason);
            return u && u > Date.now();
        }
    };

    function show(overlay){
        console.log('in show method:' + overlay);
        if(!overlay || overlay.classList.contains("is-open")) return;

        // Before showing, honor localStorage dismissal (cross-page + current page)
        if(overlay._dismissReason){
            const id = overlay.id || '';
            const memMs = parseInt(overlay.getAttribute('data-dismiss-for-ms')||'0',10);
            if(memMs > 0 && store.isDismissedNow(id, overlay._dismissReason)){
                console.log('skipping show due to dismissal');
                return;
            }
        }

        overlay.classList.add("is-open");
        overlay.setAttribute("aria-hidden","false");

        const ms = parseInt(overlay.getAttribute("data-dismiss-ms")||"0",10);
        if(ms>0){
            clearTimeout(overlay._dismissTimer);
            overlay._dismissTimer = setTimeout(function(){ close(overlay); }, ms);
        }

        // When shown via exit/idle, set a dismissal flag for configured duration
        if(overlay._dismissReason){
            const memoryMs = parseInt(overlay.getAttribute('data-dismiss-for-ms')||'0',10);
            if(memoryMs > 0){
                store.setUntil(overlay.id || '', overlay._dismissReason, memoryMs);
            }
        }

        // Focus trap entry point (focus first focusable)
        try{
            const btn = overlay.querySelector('.gosmartmodal-close');
            if(btn){ btn.focus({preventScroll:true}); }
        }catch(e){}
    }

    function close(overlay){
        if(!overlay) return;
        overlay.classList.remove("is-open");
        overlay.setAttribute("aria-hidden","true");
        if(overlay._dismissTimer){
            clearTimeout(overlay._dismissTimer);
            overlay._dismissTimer=null;
        }
    }

    function navigateFromButton(anchor, overlay){
        const href = (anchor.getAttribute('href')||'').trim();
        // Close the overlay first
        close(overlay);
        // Prevent navigating to unsafe protocols
            if(!href || /^\s*javascript:/i.test(href) || /^\s*data:/i.test(href)){
                return;
            }
            if(href.charAt(0)==='#'){
                const id = href.substring(1);
                const el = document.getElementById(id) || document.querySelector('[name="'+(window.CSS&&CSS.escape?CSS.escape(id):id)+'"]');
            if(el && typeof el.scrollIntoView==='function'){ setTimeout(function(){ el.scrollIntoView({behavior:'smooth',block:'start'}); }, 50); }
            try{ if(location.hash !== href){ history.pushState(null,'',href); } }catch(e){ location.hash = href; }
            } else {
                window.location.href = href;
            }
        }

    function installCommonHandlers(overlay){ if(!overlay._handlersInstalled){ overlay._handlersInstalled=true;
            // Clicking close/backdrop closes modal
            const closeBtn = overlay.querySelector('.gosmartmodal-close'); if(closeBtn){ closeBtn.addEventListener('click', function(){ close(overlay); }); }
            overlay.addEventListener('click', function(ev){ const isBackdrop = ev.target.classList.contains('gosmartmodal-overlay') || ev.target.classList.contains('gosmartmodal-backdrop'); if(isBackdrop){ close(overlay); } });
            // Any link with class gosmartmodalbutton closes and navigates
            overlay.addEventListener('click', function(ev){ const a = ev.target.closest('a.gosmartmodalbutton'); if(!a){ return; } ev.preventDefault(); navigateFromButton(a, overlay); });
            // ESC key to close when open
            document.addEventListener('keydown', function(ev){ if(ev.key==='Escape' && overlay.classList.contains('is-open')){ close(overlay); } });
        } }

    function installExitIntent(){ if(exitHandlerInstalled) return; exitHandlerInstalled = true;
            // let fired=false;  // REMOVE this line
            const TOP_THRESHOLD = 20; // px from top to consider as leaving toward browser chrome
            const MIN_TIME_MS = 1500; // don't fire immediately on page load
            const startTs = Date.now();
            const passiveTrue = {passive:true};

            // Track recent mouse moves to detect fast upward motion
            const lastMoves = []; // {y, t}
            const MAX_SAMPLES = 8;
            const WINDOW_MS = 400;
            function recordMove(e){ const t = Date.now();
                lastMoves.push({ y: e.clientY, t: t });
                while (lastMoves.length > MAX_SAMPLES || (t - lastMoves[0].t) > WINDOW_MS) {
                    lastMoves.shift();
                }
            }
            function fastUpwardMotion(){ if(lastMoves.length < 2) return false; const first = lastMoves[0]; const last = lastMoves[lastMoves.length - 1];
                const dy = first.y - last.y; // positive when moving up
                const dt = last.t - first.t; if(dt <= 0) return false;
                const vy = dy / dt; // px per ms
                return (dy >= 40 && vy >= 0.25); // moved up at least 40px with decent speed
            }
            function eligible(){
                // REMOVE per-page "fired" check:
                // if(fired) return false;
                if((Date.now() - startTs) < MIN_TIME_MS) return false;
                // Avoid firing when user is actively interacting with form fields
                const ae = document.activeElement;
                if(ae){
                    const tag = (ae.tagName||'').toLowerCase();
                    if(tag==='input' || tag==='textarea' || tag==='select' || ae.isContentEditable){ return false; }
                }
                return true;
            }
            function fireOnce(){ if(!eligible()) return;
                // fired = true;  // REMOVE this line
                exitOverlays.forEach(function(ov){
                    show(ov); // show() will now decide based on localStorage
                });
                // IMPORTANT: DO NOT remove listeners anymore – we want them to keep firing
                // document.removeEventListener('mousemove', onMouseMove, passiveTrue);
                // document.removeEventListener('mouseout', onMouseOut, passiveTrue);
                // window.removeEventListener('mouseleave', onMouseLeave, passiveTrue);
                // document.removeEventListener('pointerout', onPointerOut, passiveTrue);
            }

            function topEdge(e){ const y = e.clientY; return (typeof y === 'number' && y <= TOP_THRESHOLD); }
            function onMouseMove(e){ recordMove(e); }
            function onMouseOut(e){ e = e || window.event; const from = e.relatedTarget || e.toElement;
            // If moving to an element inside the document (not HTML root), ignore
            if(from && from.nodeName && from.nodeName !== 'HTML'){ return; }
            if(topEdge(e) && (fastUpwardMotion() || e.clientY <= 0)){ fireOnce(); }
        }
        function onMouseLeave(){ fireOnce(); }
        function onPointerOut(e){
            // Consider only mouse pointers; ignore touch/pen
            if(e.pointerType && e.pointerType !== 'mouse'){ return; }
            const to = e.relatedTarget;
            if(to && to.nodeType === 1 && to !== document.documentElement && to !== document.body){ return; }
            if(topEdge(e)){ fireOnce(); }
        }

        document.addEventListener('mousemove', onMouseMove, passiveTrue);
        document.addEventListener('mouseout', onMouseOut, passiveTrue);
        window.addEventListener('mouseleave', onMouseLeave, passiveTrue);
        document.addEventListener('pointerout', onPointerOut, passiveTrue);
    }

    function installIdle(overlay, seconds){ let timeout=null; let cleared=false; let ms = Math.max(0, (parseFloat(seconds)||0)*1000);
        function trigger(){
                // REMOVE per-page guard:
                // if(overlay._shown) return;
                // overlay._shown=true;
                show(overlay); // show() now respects localStorage
                // detach(); // REMOVE this – we want the idle watcher to keep running
            }
            function reset(){ 
                // you can drop 'cleared' entirely, or just keep it but never set to true
                if(/* cleared */ false) return; 
                if(timeout){ clearTimeout(timeout); } 
                timeout = setTimeout(trigger, ms); 
            }
            function onActivity(){ reset(); }
            function attach(){ ['mousemove','keydown','scroll','touchstart','wheel'].forEach(function(evt){ window.addEventListener(evt, onActivity, {passive:true}); }); reset(); }
            function detach(){ 
                // no-op or keep existing code but never call detach()
                cleared=true; 
                ['mousemove','keydown','scroll','touchstart','wheel'].forEach(function(evt){ window.removeEventListener(evt, onActivity, {passive:true}); }); 
                if(timeout){ clearTimeout(timeout); timeout=null; } 
            }
            attach(); idleControllers.push({detach:detach}); }

    function installScroll(overlay, amount, unit){
        amount = parseFloat(amount)||0;
        unit = (unit||'%').toLowerCase();
        function docMetrics(){
            const d = document;
            const b = d.body; const e = d.documentElement;
            const scrollTop = window.pageYOffset || e.scrollTop || b.scrollTop || 0;
            const viewport = window.innerHeight || e.clientHeight || 0;
            const docHeight = Math.max(
                b.scrollHeight, e.scrollHeight,
                b.offsetHeight, e.offsetHeight,
                b.clientHeight, e.clientHeight
            );
            const maxScrollable = Math.max(0, docHeight - viewport);
            return { scrollTop: scrollTop, maxScrollable: maxScrollable };
        }
        function thresholdPx(){
            const m = docMetrics();
            if(unit === '%'){
                return m.maxScrollable * (amount/100);
            }
            // px
            return amount;
        }
        function check(){
            const m = docMetrics();
            const th = thresholdPx();
            if(m.scrollTop >= th){
                show(overlay);
            }
        }
        window.addEventListener('scroll', check, {passive:true});
        window.addEventListener('resize', check, {passive:true});
        // Initial check in case page loads already past threshold
        check();
    }

    function init(){ const overlays = document.querySelectorAll('.gosmartmodal-overlay'); overlays.forEach(function(overlay){ installCommonHandlers(overlay);
            const trig = overlay.getAttribute('data-trigger'); const pos = overlay.getAttribute('data-position')||'center'; overlay.classList.add(pos==='top'?'pos-top':(pos==='bottom'?'pos-bottom':'pos-center'));

            // Build "reason" key similar to js.js: "exit" or "idle:SECONDS"
            let reasonKey = null;
            if(trig === 'exit'){
                reasonKey = 'exit';
            } else if(trig === 'idle'){
                const secsAttr = overlay.getAttribute('data-idle-seconds') || '0';
                const secs = parseFloat(secsAttr) || 0;
                reasonKey = 'idle:' + secs;
            } else if(trig === 'scroll'){
                const amtAttr = overlay.getAttribute('data-scroll-amount') || '0';
                const unit = (overlay.getAttribute('data-scroll-unit') || '%');
                const amt = parseFloat(amtAttr) || 0;
                reasonKey = 'scroll:' + amt + (unit||'%');
            }

            // If this overlay was dismissed within the configured window, skip wiring it
            const memoryMsInit = parseInt(overlay.getAttribute('data-dismiss-for-ms')||'0',10);
            if(reasonKey && memoryMsInit > 0 && store.isDismissedNow(overlay.id || '', reasonKey)){
                return;
            }

            // Store reason key on the overlay so show() can persist the dismissal
            if(reasonKey){
                overlay._dismissReason = reasonKey;
            }

            if(trig==='exit'){
                exitOverlays.push(overlay); installExitIntent();
            }
            else if(trig==='idle'){
                const secs = parseFloat(overlay.getAttribute('data-idle-seconds')||'0')||0; installIdle(overlay, secs);
            }
            else if(trig==='scroll'){
                const amt = parseFloat(overlay.getAttribute('data-scroll-amount')||'0')||0;
                const unit = (overlay.getAttribute('data-scroll-unit')||'%');
                installScroll(overlay, amt, unit);
            }
        }); }

    if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
</script>
GOSM_JS;
		}

		// Build each modal overlay.
		$i = 0;
		foreach ( $sections as $sec ) {
			++$i;
			$overlay_id = $base_id . '-' . $i;
			$pos_class  = 'center';
			if ( in_array( $sec['position'], [ 'top', 'center', 'bottom' ], true ) ) {
				$pos_class = $sec['position'];
			}

			$trigger_attr    = esc_attr( $sec['trigger'] );
			$idle_attr       = 'idle' === $sec['trigger'] ? ' data-idle-seconds="' . esc_attr( (string) $sec['idle_seconds'] ) . '"' : '';
			$scroll_attr     = 'scroll' === $sec['trigger']
				? ' data-scroll-amount="' . esc_attr( (string) ( $sec['scroll_amount'] ?? 0 ) ) . '" data-scroll-unit="' . esc_attr( (string) ( $sec['scroll_unit'] ?? '%' ) ) . '"'
				: '';
			$dismiss_attr    = $sec['dismiss_ms'] > 0 ? ' data-dismiss-ms="' . esc_attr( (string) $sec['dismiss_ms'] ) . '"' : ' data-dismiss-ms="0"';
			$position_attr   = ' data-position="' . esc_attr( $pos_class ) . '"';
			$memory_attr     = ' data-dismiss-for-ms="' . esc_attr( (string) ( $sec['dismiss_for_ms'] ?? ( 30 * 60 * 1000 ) ) ) . '"';
			$container_style = ' style="max-width:' . esc_attr( $width_css ) . '"';

			$html  = '<div class="gosmartmodal-overlay" role="dialog" aria-modal="true" aria-hidden="true" id="' . esc_attr( $overlay_id ) . '" data-trigger="' . $trigger_attr . '"' . $idle_attr . $scroll_attr . $dismiss_attr . $position_attr . $memory_attr . '>';
			$html .= '  <div class="gosmartmodal-backdrop" aria-hidden="true"></div>';
			$html .= '  <div class="gosmartmodal-container"' . $container_style . '>';
			$html .= '    <button type="button" class="gosmartmodal-close" aria-label="Close"></button>';
			// NOTE: $sec['html'] contains admin-authored content (shortcodes already processed).
			// Attributes and container values are escaped; we intentionally output inner HTML
			// without additional escaping to preserve legitimate markup from trusted editors.
			$html .= '    <div class="gosmartmodal-content">' . $sec['html'] . '</div>';
			$html .= '  </div>';
			$html .= '</div>';

			$out[] = $html;
		}

		return implode( "\n", $out );
	}
}

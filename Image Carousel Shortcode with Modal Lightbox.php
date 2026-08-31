<?php
/**
 * Image Carousel Shortcode with Modal Lightbox
 *
 * @wordpress
 *
 * GOAL:
 * Creates a responsive image carousel with navigation arrows, dots, touch/swipe support, and modal
 * lightbox for full-size image viewing. Accepts image URLs or img tags as content.
 *
 * CONFIGURATION:
 * Optional: Override default styles by targeting class names in theme CSS Optional: Customize
 * arrow symbols, colors, transitions
 *
 * USAGE:
 * # Method 1 (URLs)
 *
 * `[image_carousel]` `https://example.com/image1.jpg` `https://example.com/image2.jpg`
 * `https://example.com/image3.jpg` `[/image_carousel]`
 *
 * # Method 2 (IMG tags)
 *
 * `[image_carousel]` `<img src="https://example.com/image1.jpg" alt="Description" />` `<img
 * src="https://example.com/image2.jpg" alt="Description" />` `[/image_carousel]`
 *
 * NOTES:
 * First slide displays by default Swipe requires 50px minimum movement to trigger Modal displays
 * same image in larger size Click outside modal to close CSS uses transform for smooth animations
 */

add_action(
	'init',
	function () {
		add_shortcode(
			'image_carousel',
			function ( $atts, $content = null ) {
				static $instance = 0;
				++$instance;
				if ( empty( $content ) ) {
					return '';
				}

				// Register inline assets and enqueue only when shortcode is used
				if ( function_exists( 'wp_add_inline_style' ) ) {
					wp_register_style( 'custom-carousel-style', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
					ob_start();
					?>
					<style>
                        .custom-carousel-wrapper {
                            position: relative;
                            max-width: 100%;
                            overflow: hidden;
                        }
                        .custom-carousel-container {
                            position: relative;
                            width: 100%;
                        }
                        .custom-carousel-slide {
                            display: none;
                            text-align: center;
                        }
                        .custom-carousel-slide img {
                            max-width: 100%;
                            height: auto;
                        }
                        .custom-carousel-arrow {
                            position: absolute;
                            top: 50%;
                            transform: translateY(-50%);
                            background: none;
                            border: none;
                            font-size: 2rem;
                            cursor: pointer;
                            z-index: 10;
                        }
                        .custom-carousel-prev {
                            left: 10px;
                        }
                        .custom-carousel-next {
                            right: 10px;
                        }
                        .custom-carousel-dots {
                            text-align: center;
                            margin-top: 10px;
                        }
                        .custom-carousel-dot {
                            display: inline-block;
                            width: 12px;
                            height: 12px;
                            margin: 0 4px;
                            background: #ccc;
                            border-radius: 50%;
                            cursor: pointer;
                        }
                        .custom-carousel-dot.active {
                            background: #333;
                        }
                        .custom-modal {
                            display: none; /* Hidden by default */
                            position: fixed;
                            z-index: 1000;
                            padding-top: 60px;
                            left: 0;
                            top: 0;
                            width: 100%;
                            height: 100%;
                            overflow: auto;
                            background-color: rgba(0, 0, 0, 0.9); /* Black w/ opacity */
                        }
                        .custom-modal-content {
                            margin: auto;
                            display: block;
                            max-width: 80%;
                            max-height: 80%;
                            animation: zoom 0.6s;
                        }
                        @keyframes zoom {
                            from {
                                transform: scale(0)
                            }
                            to {
                                transform: scale(1)
                            }
                        }
                        .custom-modal-close {
                            position: absolute;
                            top: 10px;
                            right: 25px;
                            color: #fff;
                            font-size: 35px;
                            font-weight: bold;
                            cursor: pointer;
                            background: transparent;
                            border: 0;
                        }
                        .custom-modal-close:hover,
                        .custom-modal-close:focus {
                            color: #999;
                            text-decoration: none;
                            cursor: pointer;
                        }
                        .custom-modal-caption {
                            display: block;
                            text-align: center;
                            color: #fff;
                            font-size: 20px;
                            margin: 10px auto auto;
                        }
					</style>
					<?php
					$styles = ob_get_clean();
					wp_add_inline_style(
						'custom-carousel-style',
						$styles
					);

					wp_register_script( 'custom-carousel-script', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, WordPress.WP.EnqueuedResourceParameters.NotInFooter
					ob_start();
					?>
					<script>

                        document.addEventListener("DOMContentLoaded", function () {
                            document.querySelectorAll(".custom-carousel-wrapper").forEach(wrapper => {
                                const slides = wrapper.querySelectorAll(".custom-carousel-slide");
                                const dots = wrapper.querySelectorAll(".custom-carousel-dot");
                                const prevBtn = wrapper.querySelector(".custom-carousel-prev");
                                const nextBtn = wrapper.querySelector(".custom-carousel-next");
                                let current = 0;

                                function showSlide(index) {
                                    slides.forEach((slide, i) => {
                                        slide.style.display = i === index ? "block" : "none";
                                    });
                                    dots.forEach((dot, i) => {
                                        const isActive = i === index;
                                        dot.classList.toggle("active", isActive);
                                        dot.setAttribute("aria-selected", isActive ? "true" : "false");
                                        dot.setAttribute("tabindex", isActive ? "0" : "-1");
                                    });
                                    const announcement = wrapper.querySelector('.carousel-sr-announce');
                                    if (announcement) {
                                        announcement.textContent = `Slide ${index + 1} of ${slides.length}`;
                                    }
                                }

                                function changeSlide(delta) {
                                    current = (current + delta + slides.length) % slides.length;
                                    showSlide(current);
                                }

                                dots.forEach(dot => {
                                    dot.addEventListener("click", () => {
                                        current = parseInt(dot.dataset.dotIndex);
                                        showSlide(current);
                                    });
                                });

                                if (prevBtn) prevBtn.addEventListener("click", () => changeSlide(-1));
                                if (nextBtn) nextBtn.addEventListener("click", () => changeSlide(1));

                                showSlide(current);

                                // Touch swipe support
                                let startX = 0;
                                let endX = 0;

                                wrapper.addEventListener("touchstart", (e) => {
                                    startX = e.changedTouches[0].screenX;
                                });

                                wrapper.addEventListener("touchend", (e) => {
                                    endX = e.changedTouches[0].screenX;
                                    const deltaX = endX - startX;

                                    if (Math.abs(deltaX) > 50) {
                                        if (deltaX > 0) {
                                            changeSlide(-1); // swipe right → previous
                                        } else {
                                            changeSlide(1); // swipe left → next
                                        }
                                    }
                                });

                                // Modal per instance
                                const modal = wrapper.querySelector(".custom-modal");
                                if (modal) {
                                    const modalImage = modal.querySelector(".custom-modal-content");
                                    const modalCaption = modal.querySelector(".custom-modal-caption");
                                    const modalClose = modal.querySelector(".custom-modal-close");
                                    const closeModal = () => {
                                        modal.style.display = "none";
                                        modal.setAttribute("aria-hidden", "true");
                                    };
                                    wrapper.querySelectorAll("img").forEach(img => {
                                        img.addEventListener("error", function() {
                                            this.style.border = "2px solid red";
                                            this.alt = "Failed to load image";
                                        });
                                    });
                                    wrapper.querySelectorAll(".custom-carousel-link").forEach(link => {
                                        link.addEventListener("click", function (e) {
                                            e.preventDefault();
                                            const fullSrc = this.dataset.fullSrc;
                                            if (fullSrc) {
                                                modal.style.display = "block";
                                                modal.setAttribute("aria-hidden", "false");
                                                if (modalImage) modalImage.src = fullSrc;
                                                const img = this.querySelector("img");
                                                if (modalCaption) modalCaption.textContent = img ? (img.getAttribute("alt") || "") : "";
                                                if (modalClose && modalClose.focus) modalClose.focus();
                                            }
                                        });
                                    });
                                    if (modalClose) modalClose.addEventListener("click", closeModal);
                                    modal.addEventListener("click", function (e) {
                                        if (e.target === modal) closeModal();
                                    });

                                    const focusableElements = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                                    const firstFocusable = focusableElements[0];
                                    const lastFocusable = focusableElements[focusableElements.length - 1];

                                    modal.addEventListener('keydown', function(e) {
                                        if (e.key === 'Tab') {
                                            if (e.shiftKey) { // Shift + Tab
                                                if (document.activeElement === firstFocusable) {
                                                    e.preventDefault();
                                                    lastFocusable.focus();
                                                }
                                            } else { // Tab
                                                if (document.activeElement === lastFocusable) {
                                                    e.preventDefault();
                                                    firstFocusable.focus();
                                                }
                                            }
                                        }
                                    });

                                    document.addEventListener("keydown", function (e) {
                                        if (e.key === "Escape" && modal.style.display === "block") {
                                            closeModal();
                                        } else if (e.key === "ArrowLeft") {
                                            e.preventDefault();
                                            changeSlide(-1);
                                        } else if (e.key === "ArrowRight") {
                                            e.preventDefault();
                                            changeSlide(1);
                                        }
                                    });
                                }
                            });
                        });

					</script>
					<?php
					$script = ob_get_clean();
					wp_add_inline_script(
						'custom-carousel-script',
						$script
					);
				}
				wp_enqueue_style( 'custom-carousel-style' );
				wp_enqueue_script( 'custom-carousel-script' );

				$content = wp_kses_post( $content );
				$images  = [];

				$prev = libxml_use_internal_errors( true );
				$doc  = new DOMDocument();
				$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
				libxml_clear_errors();
				libxml_use_internal_errors( $prev );

				$xpath = new DOMXPath( $doc );
				$imgs  = $xpath->query( '//img' );

				foreach ( $imgs as $img ) {
					if ( $img->hasAttribute( 'src' ) ) {
						$images[] = $img->getAttribute( 'src' );
					}
				}

				if ( empty( $images ) ) {
					// If no <img>, parse as newline-separated URLs
					$cleaned_string = str_replace( '<br />', "\n", $content );
					$lines          = explode( "\n", $cleaned_string );
					foreach ( $lines as $line ) {
						$line = trim( wp_strip_all_tags( $line ) );
						if ( '' === $line ) {
							continue;
						}
						$images[] = $line;
					}
				}

				// Filter only http/https URLs and sanitize
				$images = array_values(
					array_filter(
						array_map(
							static function ( $u ) {
								$u = trim( (string) $u );
								if ( '' === $u ) {
									return null; }
								$valid = wp_http_validate_url( $u );
								if ( ! $valid ) {
									return null; }
								$parts = wp_parse_url( $u );
								if ( ! $parts || ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
									return null; }
								return esc_url_raw( $u );
							},
							$images
						),
						static function ( $x ) {
							return ! empty( $x ); }
					)
				);

				if ( empty( $images ) ) {
					return '';
				}

				$wrapper_id = 'custom-carousel-' . $instance;
				$modal_id   = $wrapper_id . '-modal';

				$output  = '<div class="custom-carousel-wrapper" id="' . esc_attr( $wrapper_id ) . '" role="region" aria-label="Image Carousel">';
				$output .= '<div class="custom-carousel-container">';

				foreach ( $images as $index => $url ) {
					$url     = esc_url( $url );
					$output .= '<div class="custom-carousel-slide" data-slide-index="' . esc_attr( $index ) . '">';
					$output .= '<a href="#" class="custom-carousel-link" data-full-src="' . $url . '">';
					$output .= '<img src="' . $url . '" alt="" loading="lazy" />';
					$output .= '</a></div>';
				}

				$output .= '</div>'; // .custom-carousel-container
				$output .= '<div class="carousel-sr-announce" aria-live="polite" aria-atomic="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">Slide 1 of ' . count( $images ) . '</div>';
				$output .= '<button class="custom-carousel-arrow custom-carousel-prev" type="button" aria-label="Previous">&#10094;</button>';
				$output .= '<button class="custom-carousel-arrow custom-carousel-next" type="button" aria-label="Next">&#10095;</button>';

				$output .= '<div class="custom-carousel-dots" role="tablist" aria-label="Carousel slides">';
				foreach ( $images as $index => $_ ) {
					$is_active = ( 0 === $index );
					$output   .= '<button class="custom-carousel-dot' . ( $is_active ? ' active' : '' ) . '" type="button" role="tab" tabindex="' . ( $is_active ? '0' : '-1' ) . '" aria-selected="' . ( $is_active ? 'true' : 'false' ) . '" data-dot-index="' . esc_attr( $index ) . '" aria-label="Go to slide ' . esc_attr( $index + 1 ) . '"></button>';
				}
				$output .= '</div>'; // .custom-carousel-dots

				// Per-instance modal structure
				$output .= '
        <div id="' . esc_attr( $modal_id ) . '" class="custom-modal" aria-modal="true" role="dialog" aria-label="Image viewer" aria-hidden="true">
            <button class="custom-modal-close" type="button" aria-label="Close">&times;</button>
            <img class="custom-modal-content" alt="">
            <div class="custom-modal-caption"></div>
        </div>
    ';

				$output .= '</div>'; // .custom-carousel-wrapper

				return $output;
			}
		);
	}
);

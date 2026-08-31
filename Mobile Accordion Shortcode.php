<?php
/**
 * Mobile Accordion Shortcode
 *
 * @wordpress
 *
 * GOAL:
 * Creates a mobile-friendly accordion component that parses nested `divs` into collapsible
 * sections. Supports nested shortcodes and allows only one section to be open at a time.
 * **CONFIGURATION**
 *
 * - CSS: Add styles for .mobile-accordion-wrapper, .accordion-section, .accordion-label,
 *   .accordion-content
 * - CSS: Define .open and .visible classes for active states
 * - Structure: Content must follow specific format (see usage below)
 *
 * USAGE:
 * `[mobile_accordion]` `<div>` `<div>Section 1 Title</div>` `<div>Section 1 content goes
 * here...</div>` `</div>` `<div>` `<div>Section 2 Title</div>` `<div>Section 2 content goes
 * here...</div>` `</div>` `[/mobile_accordion]`
 *
 * NOTES:
 * - First section opens by default
 * - Supports nested shortcodes
 */

add_shortcode(
	'mobile_accordion',
	function ( $atts, $content = null ) {
		static $instance = 0;
		++$instance;

		// This line alone ensures all [gfsearch] and nested shortcodes run
		$content = do_shortcode( $content );

		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="accordion-wrapper">' . $content . '</div>' );

		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		// Return early if HTML structure is critically malformed
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $error ) {
				if ( LIBXML_ERR_ERROR === $error->level || LIBXML_ERR_FATAL === $error->level ) {
					return '<!-- Mobile Accordion: Malformed HTML structure detected -->';
				}
			}
		}

		$xpath      = new DOMXPath( $doc );
		$containers = $xpath->query( '//*[@id="accordion-wrapper"]/div' );

		$accordion_items = [];

		foreach ( $containers as $container ) {
			$children = [];
			foreach ( $container->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( XML_ELEMENT_NODE === $child->nodeType && 'div' === $child->tagName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$children[] = $child;
				}
			}

			if ( count( $children ) < 2 ) {
				continue;
			}

			$label        = trim( $children[0]->textContent );
			$content_html = '';
			foreach ( $children[1]->childNodes as $node ) {
				$content_html .= $doc->saveHTML( $node );
			}

			$accordion_items[] = [
				'label'   => esc_html( $label ),
				'content' => wp_kses_post( $content_html ),
			];
		}

		if ( empty( $accordion_items ) ) {
			return '<!-- Mobile Accordion: No valid accordion sections found. Ensure content follows the correct structure. -->';
		}

		$id     = 'mobile-accordion-' . $instance;
		$output = '<div class="mobile-accordion-wrapper" id="' . esc_attr( $id ) . '">';

		foreach ( $accordion_items as $i => $item ) {
			$btn_id   = $id . '-btn-' . $i;
			$panel_id = $id . '-panel-' . $i;
			$is_open  = ( 0 === $i );
			$output  .= '
            <div class="accordion-section">
                <button id="' . esc_attr( $btn_id ) . '" class="accordion-label' . ( $is_open ? ' open' : '' ) . '" type="button" aria-expanded="' . ( $is_open ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $panel_id ) . '" data-accordion="' . esc_attr( $id . '-' . $i ) . '">' . $item['label'] . '</button>
                <div id="' . esc_attr( $panel_id ) . '" class="accordion-content' . ( $is_open ? ' visible' : '' ) . '" role="region" aria-labelledby="' . esc_attr( $btn_id ) . '" data-accordion="' . esc_attr( $id . '-' . $i ) . '"' . ( $is_open ? '' : ' hidden' ) . '>' . $item['content'] . '</div>
            </div>
        ';
		}

		$output .= '</div>';

		ob_start();
		?>
		<script>
            document.addEventListener('DOMContentLoaded', () => {
                const wrapper = document.getElementById("<?php echo esc_js( $id ); ?>");
                if (!wrapper) return;
                const labels = Array.from(wrapper.querySelectorAll('.accordion-label'));
                const contents = Array.from(wrapper.querySelectorAll('.accordion-content'));

                function openItem(label) {
                    const target = label.getAttribute('data-accordion');
                    labels.forEach(l => { l.classList.remove('open'); l.setAttribute('aria-expanded', 'false'); });
                    contents.forEach(c => { c.classList.remove('visible'); c.setAttribute('hidden', ''); });
                    label.classList.add('open');
                    label.setAttribute('aria-expanded', 'true');
                    const panel = wrapper.querySelector('.accordion-content[data-accordion="' + target + '"]');
                    if (panel) { panel.classList.add('visible'); panel.removeAttribute('hidden'); }
                }

                labels.forEach((label, idx) => {
                    label.addEventListener('click', () => openItem(label));
                    label.addEventListener('keydown', (e) => {
                        let newIndex = null;
                        if (e.key === 'ArrowDown') newIndex = (idx + 1) % labels.length;
                        else if (e.key === 'ArrowUp') newIndex = (idx - 1 + labels.length) % labels.length;
                        else if (e.key === 'Home') newIndex = 0;
                        else if (e.key === 'End') newIndex = labels.length - 1;
                        else if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            openItem(label);
                            return;
                        }
                        if (newIndex !== null) {
                            e.preventDefault();
                            labels[newIndex].focus();
                        }
                    });
                });
            });
		</script>
		<?php
		// Capture the output and append it to the main output
		$output .= ob_get_clean();
		return $output;
	}
);
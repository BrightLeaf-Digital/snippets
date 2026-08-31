<?php
/**
 * Custom Tabbed Content Shortcode
 *
 * @wordpress
 *
 * GOAL:
 * **GOAL** Creates a tabbed interface by parsing nested div structures into clickable tabs with
 * associated content panels. Supports nested shortcodes and DOM-based parsing.
 *
 * CONFIGURATION:
 * CSS: Style .tabbed-content-wrapper, .tabbed-content-tabs, .tab-label, .tab-panel CSS: Define
 * .active class for selected tab and visible panel Structure: Content must follow specific div
 * nesting (see usage)
 *
 * USAGE:
 * `[tabbed_content]` `<div>` `<div>Tab 1 Label</div>` `<div>Tab 1 content here...</div>` `</div>`
 * `<div>` `<div>Tab 2 Label</div>` `<div>Tab 2 content here...</div>` `</div>` `[/tabbed_content]`
 *
 * NOTES:
 * First tab is active by default Supports nested shortcodes Only one panel visible at a time
 */

add_shortcode(
	'tabbed_content',
	function ( $atts, $content = null ) {
		static $instance = 0;
		++$instance;

		static $script_enqueued = false;
		if ( ! $script_enqueued ) {
			wp_register_script( 'bld-tabbed-content', '', [], false, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
			wp_enqueue_script( 'bld-tabbed-content' );
			$script_enqueued = true;
		}

		$atts = shortcode_atts(
			[
				'active' => 0, // Default to first tab
			],
			$atts,
			'tabbed_content'
		);

		$active_index = absint( $atts['active'] );

		$content = do_shortcode( $content );

		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="wrapper">' . $content . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath      = new DOMXPath( $doc );
		$containers = $xpath->query( '//*[@id="wrapper"]/div' );

		$labels   = [];
		$contents = [];

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

			$label_text   = trim( $children[0]->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$content_html = '';
			foreach ( $children[1]->childNodes as $node ) {
				$content_html .= $doc->saveHTML( $node );
			}

			$labels[]   = esc_html( $label_text );
			$contents[] = wp_kses_post( $content_html );
		}

		if ( empty( $labels ) ) {
			return '';
		}

		$output = '<div class="tabbed-content-wrapper" id="tabbed-content-' . esc_attr( $instance ) . '">';

		// Tabs
		$output .= '<div class="tabbed-content-tabs" role="tablist" aria-label="Tabbed content">';
		foreach ( $labels as $i => $label ) {
			$tab_id   = 'tab-label-' . $instance . '-' . $i;
			$panel_id = 'tab-panel-' . $instance . '-' . $i;
			$active   = ( $active_index === $i );
			$output  .= '<button id="' . esc_attr( $tab_id ) . '" class="tab-label' . ( $active ? ' active' : '' ) . '" role="tab" aria-selected="' . ( $active ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $panel_id ) . '" data-tab="' . esc_attr( $instance . '-' . $i ) . '" type="button"' . ( $active ? '' : ' tabindex="-1"' ) . '>' . $label . '</button>';
		}
		$output .= '</div>';

		// Panels
		$output .= '<div class="tabbed-content-panels">';
		foreach ( $contents as $i => $html ) {
			$panel_id = 'tab-panel-' . $instance . '-' . $i;
			$tab_id   = 'tab-label-' . $instance . '-' . $i;
			$active   = ( $active_index === $i );
			$output  .= '<div id="' . esc_attr( $panel_id ) . '" class="tab-panel' . ( $active ? ' active' : '' ) . '" role="tabpanel" aria-labelledby="' . esc_attr( $tab_id ) . '" data-tab="' . esc_attr( $instance . '-' . $i ) . '"' . ( $active ? '' : ' hidden' ) . '>' . $html . '</div>';
		}
		$output .= '</div></div>';

		ob_start();
		?>
		<script>
            document.addEventListener('DOMContentLoaded', () => {
                const wrapper = document.getElementById('<?php echo esc_js( 'tabbed-content-' . $instance ); ?>');
                if (!wrapper) return;
                const tabs = Array.from(wrapper.querySelectorAll('.tab-label'));
                const panels = Array.from(wrapper.querySelectorAll('.tab-panel'));

                function activateTab(newTab) {
                    const target = newTab.getAttribute('data-tab');
                    tabs.forEach(t => {
                        t.classList.remove('active');
                        t.setAttribute('aria-selected', 'false');
                        t.setAttribute('tabindex', '-1');
                    });
                    panels.forEach(p => {
                        p.classList.remove('active');
                        p.setAttribute('hidden', '');
                    });
                    newTab.classList.add('active');
                    newTab.setAttribute('aria-selected', 'true');
                    newTab.removeAttribute('tabindex');
                    const panel = wrapper.querySelector('.tab-panel[data-tab="' + target + '"]');
                    if (panel) { panel.classList.add('active'); panel.removeAttribute('hidden'); }
                    newTab.focus();
                    window.history.replaceState(null, null, '#' + newTab.id);
                }

                tabs.forEach((tab, idx) => {
                    tab.addEventListener('click', () => activateTab(tab));
                    tab.addEventListener('keydown', (e) => {
                        let newIndex = null;
                        if (e.key === 'ArrowRight') newIndex = (idx + 1) % tabs.length;
                        else if (e.key === 'ArrowLeft') newIndex = (idx - 1 + tabs.length) % tabs.length;
                        else if (e.key === 'Home') newIndex = 0;
                        else if (e.key === 'End') newIndex = tabs.length - 1;
                        if (newIndex !== null) {
                            e.preventDefault();
                            activateTab(tabs[newIndex]);
                        }
                    });
                });
                const hash = window.location.hash;
                if (hash) {
                    const hashTab = tabs.find(t => t.id === hash.substring(1));
                    if (hashTab) activateTab(hashTab);
                }
            });
		</script>
		<?php
		$script = ob_get_clean();
		wp_add_inline_script( 'bld-tabbed-content', $script );
		return $output;
	}
);

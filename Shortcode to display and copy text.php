<?php
/**
 * Shortcode to display and copy text
 *
 * @wordpress
 *
 * GOAL:
 * This snippet creates a shortcode that displays the configured text along with a copy button to
 * easily display copyable content on your site.
 *
 * USAGE:
 * - `[bl_copy]Some text to copy[/bl_copy]`
 * - `[bl_copy label="API Key"]sk_live_123...[/bl_copy]`
 * - `[bl_copy tag="span"]Text[/bl_copy]`
 *
 * # Attributes
 *
 * - label: small label shown above the value
 * - tag: wrapper tag for the value (div|span|code), default: div
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
    'wp_enqueue_scripts',
    function () {
		wp_register_style( 'bl-copythis', false, [], '1.0.0' );
		wp_register_script( 'bl-copythis', false, [], '1.0.0', true );

		wp_enqueue_style( 'bl-copythis' );
		wp_enqueue_script( 'bl-copythis' );

		wp_add_inline_style(
        'bl-copythis',
        <<<'CSS'
.bl-copythis{
    --blg-navy: #0B2148;
    --blg-green: #55833D;
    --blg-green-2: #3F854D;

    --bl-border: rgba(85,131,61,.28);
    --bl-text: rgba(11,33,72,.95);
    --bl-muted: rgba(11,33,72,.70);
    --bl-bg: #ffffff;
    --bl-surface: #F6FAF5;
    --bl-surface-2: #EFF6EE;
    --bl-focus: rgba(85,131,61,.22);
    --bl-shadow: rgba(11,33,72,.10);

    display:block;
    max-width:100%;
    font-family:inherit;
    color:var(--bl-text);
}

.bl-copythis__label{
    font-size:12px;
    line-height:1.2;
    color:var(--bl-muted);
    margin:0 0 6px 0;
}

.bl-copythis__row{
    display:grid;
    grid-template-columns: 1fr auto auto;
    gap:10px;
    align-items:center;

    background: var(--bl-bg);
    border: 1px solid rgba(85,131,61,.22);
    border-radius:12px;
    padding:10px 10px 10px 12px;
    box-shadow: 0 10px 24px rgba(11,33,72,.06);
}

.bl-copythis__value{
    min-width:0;
    display:block;
    padding:10px 12px;
    border-radius:10px;

    background: var(--bl-surface);
    border: 1px solid rgba(85,131,61,.20);

    font-size:14px;
    line-height:1.35;
    color:var(--bl-text);

    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;

    cursor:pointer;
    user-select:none;
}

.bl-copythis__value:focus{
    outline:none;
    box-shadow: 0 0 0 4px var(--bl-focus);
    border-color: rgba(85,131,61,.55);
}

.bl-copythis__btn{
    display:inline-flex;
    align-items:center;
    gap:8px;

    padding:10px 12px;
    border-radius:10px;

    border: 1px solid rgba(0,0,0,.14);
    background: linear-gradient(180deg, var(--blg-green), var(--blg-green-2));
    color:#fff;

    font-weight:800;
    font-size:13px;
    line-height:1;

    cursor:pointer;
    white-space:nowrap;

    box-shadow: 0 10px 22px rgba(11,33,72,.16);
    transition: transform .08s ease, box-shadow .15s ease, filter .15s ease;
}

.bl-copythis__btn:hover{
    filter: brightness(1.04);
    box-shadow: 0 14px 30px rgba(11,33,72,.20);
}

.bl-copythis__btn:active{
    transform: translateY(1px);
}

.bl-copythis__btn:focus{
    outline:none;
    box-shadow: 0 0 0 5px var(--bl-focus), 0 14px 30px rgba(11,33,72,.20);
}

.bl-copythis__btn-icon{
    font-size:14px;
    line-height:1;
}

.bl-copythis__status{
    font-size:12px;
    color:var(--bl-muted);
    padding-right:4px;
    white-space:nowrap;
}

.bl-copythis.is-copied .bl-copythis__row{
    border-color: rgba(85,131,61,.55);
    background: linear-gradient(180deg, var(--bl-surface-2), #fff);
    box-shadow: 0 16px 40px rgba(11,33,72,.14);
}

.bl-copythis.is-copied .bl-copythis__status{
    color: var(--blg-green-2);
    font-weight:800;
}

@media (max-width: 520px){
    .bl-copythis__row{
        grid-template-columns: 1fr auto;
        grid-template-areas:
      "value value"
      "button status";
    }
    .bl-copythis__value{ grid-area:value; }
    .bl-copythis__btn{ grid-area:button; width:fit-content; }
    .bl-copythis__status{ grid-area:status; justify-self:end; }
}
CSS
		);

		wp_add_inline_script(
        'bl-copythis',
        <<<'JS'
(function () {
    function setStatus(wrapper, msg) {
        const el = wrapper.querySelector('[data-bl-copythis-status]');
        if (!el) return;
        el.textContent = msg || '';
        if (msg) {
            wrapper.classList.add('is-copied');
            window.setTimeout(() => {
                wrapper.classList.remove('is-copied');
                el.textContent = '';
            }, 1200);
        }
    }

    async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }

        // Fallback for non-secure contexts
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.left = '-1000px';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    }

    function getValue(wrapper) {
        const valEl = wrapper.querySelector('[data-bl-copythis-value]');
        if (!valEl) return '';
        return (valEl.textContent || '').trim();
    }

    async function handleCopyFromTarget(target) {
        const wrapper = target.closest('[data-bl-copythis]');
        if (!wrapper) return;

        const text = getValue(wrapper);
        if (!text) return;

        try {
            await copyText(text);
            setStatus(wrapper, 'Copied');
        } catch (e) {
            setStatus(wrapper, 'Copy failed');
        }
    }

    document.addEventListener('click', (e) => {
        const t = e.target;
        if (t.closest('[data-bl-copythis-btn]') || t.closest('[data-bl-copythis-value]')) {
            e.preventDefault();
            handleCopyFromTarget(t);
        }
    });

    document.addEventListener('keydown', (e) => {
        const t = e.target;
        const isValue = t && t.matches && t.matches('[data-bl-copythis-value]');
        if (!isValue) return;

        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            handleCopyFromTarget(t);
        }
    });
})();
JS
		,
        'after'
        );
	},
    20
    );

add_shortcode(
    'bl_copy',
    function ( $atts, $content = null ) {
		$atts = shortcode_atts(
		[
			'label' => '',
			'tag'   => 'div',
		],
		$atts,
		'bl_copy'
		);

		$raw = do_shortcode( $content ?? '' );
		$raw = wp_strip_all_tags( $raw, false );
		$raw = trim( preg_replace( "/\r\n|\r|\n/", "\n", $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		$id    = 'bl-copythis-' . wp_generate_uuid4();
		$label = sanitize_text_field( $atts['label'] );
		$tag   = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $atts['tag'] ) );
		$tag   = in_array( $tag, [ 'div', 'span', 'code' ], true ) ? $tag : 'div';

		ob_start();
		?>
	<div class="bl-copythis" id="<?php echo esc_attr( $id ); ?>" data-bl-copythis>
		<?php if ( '' !== $label ) : ?>
			<div class="bl-copythis__label"><?php echo esc_html( $label ); ?></div>
		<?php endif; ?>

		<div class="bl-copythis__row" role="group" aria-label="Copy to clipboard">
			<<?php echo esc_attr( $tag ); ?>
				class="bl-copythis__value"
				data-bl-copythis-value
				tabindex="0"
				role="button"
				aria-label="Copy value"
			><?php echo esc_html( $raw ); ?></<?php echo esc_attr( $tag ); ?>>

			<button
				class="bl-copythis__btn"
				type="button"
				data-bl-copythis-btn
				aria-label="Copy"
				title="Copy"
			>
				<span class="bl-copythis__btn-icon" aria-hidden="true">⧉</span>
				<span class="bl-copythis__btn-text">Copy</span>
			</button>

			<span class="bl-copythis__status" data-bl-copythis-status aria-live="polite"></span>
		</div>
	</div>
		<?php
		return ob_get_clean();
	}
    );

<?php
/* phpcs:disable WordPress.Files.FileName */
/**
 * Gravity Flow Step to Create Coupon Code
 *
 * @gravityflow
 * @gravityforms
 *
 * GOAL:
 * - Adds a custom Gravity Flow step type that generates Gravity Forms Coupons dynamically during a
 *   workflow.
 * - Provides full UI to configure code, name, amount, type, target form, dates, limits, and
 *   stackability.
 * - Registers a `{coupon_code}` merge tag which outputs the code created for the entry.
 */

add_action(
	'gravityflow_loaded',
	function () {
		if ( ! class_exists( 'Gravity_Flow_Step' ) || ! class_exists( 'Gravity_Flow_Steps' ) ) {
			return;
		}
		/**
		 * Class BLD_CouponCodeWorkflowStep
		 *
		 * Represents a workflow step associated with coupon code functionality.
		 * This step type provides integration with the coupon code capability
		 * and allows specific handling or processing within a workflow.
		 *
		 * Extends the Gravity_Flow_Step class.
		 */
		class BLD_CouponCodeWorkflowStep extends Gravity_Flow_Step {
			// phpcs:disable PHPCompatibility.FunctionDeclarations.NewClosure.ThisFoundOutsideClass
			/**
			 * The step type identifier.
			 *
			 * @var string
			 */
			protected $_step_type = 'coupon-code'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

			/**
			 * Constructor for the coupon code workflow step.
			 *
			 * @param array $feed The feed object.
			 * @param array $entry The entry object.
			 */
			public function __construct( $feed = [], $entry = null ) {
				parent::__construct( $feed, $entry );
				// Add filters for custom merge tags
				add_filter( 'gform_custom_merge_tags', [ $this, 'add_coupon_code_merge_tag' ] );
				add_filter( 'gform_replace_merge_tags', [ $this, 'replace_coupon_code_merge_tag' ], 10, 5 );
			}

			/**
			 * Adds a custom merge tag for the coupon code.
			 *
			 * @param array $merge_tags The current merge tags.
			 * @return array The merge tags with the coupon code tag added.
			 */
			public function add_coupon_code_merge_tag( $merge_tags ) {
				$merge_tags[] = [
					'label' => 'Coupon Code (use {coupon_code:FORM_ID} for a target form)',
					'tag'   => '{coupon_code}',
				];
				return $merge_tags;
			}

			/**
			 * Replaces the coupon code merge tag with the actual coupon code.
			 *
			 * @param string $text The text in which to replace the merge tag.
			 * @param array  $form The current form.
			 * @param array  $entry The current entry.
			 * @param bool   $url_encode Whether to URL encode the replacement text.
			 * @param bool   $esc_html Whether to escape HTML in the replacement text.
			 *
			 * @return string The text with the merge tag replaced.
			 */
			public function replace_coupon_code_merge_tag( $text, $form, $entry, $url_encode = false, $esc_html = true ) {
				// Short-circuit for compatibility with PHP 7.4.
				if ( false === is_string( $text ) || false === strpos( $text, '{coupon_code' ) ) {
					return $text;
				}

				$pattern = '/\{coupon_code(?::(\d+))?\}/';
				return preg_replace_callback(
					$pattern,
					function ( $matches ) use ( $form, $entry, $url_encode, $esc_html ) {
						$eid            = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
						$target_form_id = isset( $matches[1] ) ? (int) $matches[1] : 0;
						if ( 0 !== $target_form_id ) {
							$coupon_code = $this->get_coupon_code_for_form( $eid, $target_form_id );
						} else {
							$coupon_code = $this->get_default_coupon_code( $eid, $form );
						}
						if ( '' === $coupon_code ) {
							$coupon_code = 'No coupon code available';
						}

						if ( true === $esc_html ) {
							$coupon_code = esc_html( $coupon_code );
						}

						if ( true === $url_encode ) {
							$coupon_code = rawurlencode( $coupon_code );
						}

						return $coupon_code;
					},
					$text
				);
			}

			/**
			 * Returns stored coupon codes keyed by target form ID for the entry.
			 *
			 * @param int $entry_id The entry ID.
			 * @return array
			 */
			private function get_entry_coupon_codes( $entry_id ) {
				if ( 0 === $entry_id ) {
					return [];
				}

				$codes = gform_get_meta( $entry_id, 'created_coupon_codes' );
				return is_array( $codes ) ? $codes : [];
			}

			/**
			 * Returns the coupon code for a specific target form.
			 *
			 * @param int $entry_id The entry ID.
			 * @param int $form_id The target form ID.
			 * @return string
			 */
			private function get_coupon_code_for_form( $entry_id, $form_id ) {
				if ( 0 === $entry_id || 0 === $form_id ) {
					return '';
				}

				$codes    = $this->get_entry_coupon_codes( $entry_id );
				$form_key = (string) $form_id;

				return isset( $codes[ $form_key ] ) && is_string( $codes[ $form_key ] ) ? $codes[ $form_key ] : '';
			}

			/**
			 * Returns the most relevant coupon code for {coupon_code} without a form ID.
			 *
			 * @param int   $entry_id The entry ID.
			 * @param array $form The current form.
			 * @return string
			 */
			private function get_default_coupon_code( $entry_id, $form ) {
				if ( 0 === $entry_id ) {
					return '';
				}

				$codes = $this->get_entry_coupon_codes( $entry_id );
				if ( isset( $form['id'] ) ) {
					$current_form_code = $this->get_coupon_code_for_form( $entry_id, (int) $form['id'] );
					if ( '' !== $current_form_code ) {
						return $current_form_code;
					}
				}

				if ( 1 === count( $codes ) ) {
					$only = array_values( $codes );
					return is_string( $only[0] ) ? $only[0] : '';
				}

				$legacy_code = gform_get_meta( $entry_id, 'created_coupon_code' );
				return is_string( $legacy_code ) ? $legacy_code : '';
			}

			/**
			 * Returns an existing coupon code for a target form, migrating legacy data when possible.
			 *
			 * @param int $entry_id The entry ID.
			 * @param int $form_id The target form ID.
			 * @return string
			 */
			private function get_existing_coupon_code( $entry_id, $form_id ) {
				$existing_code = $this->get_coupon_code_for_form( $entry_id, $form_id );
				if ( '' !== $existing_code ) {
					return $existing_code;
				}

				$legacy_code = 0 !== $entry_id ? gform_get_meta( $entry_id, 'created_coupon_code' ) : '';
				if ( '' === $legacy_code || ! is_string( $legacy_code ) ) {
					return '';
				}

				if ( true === $this->legacy_code_matches_form( $legacy_code, $form_id ) ) {
					$codes                      = $this->get_entry_coupon_codes( $entry_id );
					$codes[ (string) $form_id ] = $legacy_code;
					gform_update_meta( $entry_id, 'created_coupon_codes', $codes );
					return $legacy_code;
				}

				return '';
			}

			/**
			 * Checks whether a legacy coupon code exists on the target form.
			 *
			 * @param string $legacy_code The legacy coupon code.
			 * @param int    $form_id The target form ID.
			 * @return bool
			 */
			private function legacy_code_matches_form( $legacy_code, $form_id ) {
				if ( '' === $legacy_code || 0 === $form_id ) {
					return false;
				}

				if ( ! is_callable( 'gf_coupons' ) ) {
					return true;
				}

				$existing = gf_coupons()->get_coupons_by_codes( [ $legacy_code ], $form_id );
				return ! empty( $existing );
			}

			/**
			 * Retrieves the settings array containing configuration details.
			 *
			 * @return array An associative array including the settings, such as titles and fields.
			 */
			public function get_settings() {
				$forms         = GFAPI::get_forms();
				$validate_date = static function ( $field, $date ) {
					$date = is_string( $date ) ? trim( $date ) : '';
					if ( '' === $date ) {
						return;
					}
					// Basic format: MM/DD/YYYY
					$valid_format = ( 1 === preg_match( '/^(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])\/\d{4}$/', $date ) );
					if ( true !== $valid_format || false === strtotime( $date ) ) {
						$field->set_error( 'The date must be valid and in MM/DD/YYYY format.' );
					}
				};
				$form_choices  = array_map(
					static function ( $form ) {
						return [
							'label' => $form['title'],
							'value' => $form['id'],
						];
					},
					$forms
				);
				return [
					'title'  => 'Coupon Code',
					'fields' => [
						[
							'name'     => 'coupon_code_field',
							'type'     => 'text',
							'label'    => 'Coupon Code',
							'required' => true,
							'class'    => 'merge-tag-support mt-position-right',
							'tooltip'  => 'Enter the coupon code. Merge tags can be used to pass in the value of a field.',
						],
						[
							'name'    => 'name_field',
							'type'    => 'text',
							'label'   => 'Coupon Name',
							'tooltip' => 'Enter the coupon name. If not configured, the coupon code will be used as the name.',
							'class'   => 'merge-tag-support mt-position-right',
						],
						[
							'name'                => 'amount',
							'type'                => 'text',
							'label'               => 'Coupon Amount',
							'tooltip'             => 'Enter the amount of the coupon.',
							'required'            => true,
							'class'               => 'merge-tag-support mt-position-right',
							'validation_callback' => function ( $field, $amount ) {
								if ( is_numeric( $amount ) && $amount < 1 ) {
									$field->set_error( 'The amount must be greater than zero.' );
								}
							},
						],
						[
							'name'       => 'type',
							'type'       => 'radio',
							'horizontal' => true,
							'label'      => 'Discount Type',
							'tooltip'    => 'Select the type of discount.',
							'required'   => true,
							'choices'    => [
								[
									'label' => 'Percentage',
									'value' => 'percentage',
								],
								[
									'label' => 'Flat',
									'value' => 'flat',
								],
							],
						],
						[
							'name'    => 'target_form',
							'label'   => 'Target Form',
							'type'    => 'select',
							'choices' => $form_choices,
							'tooltip' => 'Select the form to use the coupon code in.',
						],
						[
							'name'                => 'coupon_start_date',
							'type'                => 'text',
							'label'               => 'Coupon Start Date',
							'tooltip'             => 'Enter the start date of the coupon in MM/DD/YYYY format.',
							'validation_callback' => $validate_date,
						],
						[
							'name'                => 'coupon_expiration_date',
							'type'                => 'text',
							'label'               => 'Coupon Expiration Date',
							'tooltip'             => 'Enter the expiration date of the coupon in MM/DD/YYYY format.',
							'validation_callback' => $validate_date,
						],
						[
							'name'    => 'coupon_limit',
							'type'    => 'text',
							'label'   => 'Coupon Limit',
							'tooltip' => 'Enter the number of times the coupon can be used. Default is unlimited. Merge tags can be used to pass in the value of a field.',
							'class'   => 'merge-tag-support mt-position-right',
						],
						[
							'name'    => 'stackable',
							'type'    => 'checkbox',
							'label'   => 'Stackable',
							'tooltip' => 'Check this box to allow the coupon to be used with other coupons.',
							'choices' => [
								[
									'label' => '',
									'name'  => 'stackable',
								],
							],
						],
						[
							'name'     => 'required_fields[]',
							'type'     => 'field_select',
							'label'    => 'Required Fields',
							'tooltip'  => 'Select fields that must not be empty for the coupon to be created.',
							'required' => false,
							'multiple' => true,
						],
					],
				];
			}

			/**
			 * Process the step. Create the coupon based on the form submission.
			 *
			 * @return bool Is the step complete?
			 */
			public function process() {
				$form  = $this->get_form();
				$entry = $this->get_entry();

				$eid            = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
				$target_form_id = isset( $this->target_form ) ? (int) $this->target_form : 0;
				$target_form    = 0 !== $target_form_id ? GFAPI::get_form( $target_form_id ) : null;
				if ( empty( $target_form ) || ! is_array( $target_form ) ) {
					$this->add_note( sprintf( 'Coupon not created: target form %d not found.', $target_form_id ) );
					return true;
				}

				// Idempotency: if a coupon was already created for this target form, do not create another.
				$existing_cc = $this->get_existing_coupon_code( $eid, $target_form_id );
				if ( '' !== $existing_cc ) {
					$this->add_note( sprintf( 'Coupon already created for target form %d: %s', $target_form_id, $existing_cc ) );
					return true;
				}

				if ( true === $this->should_abort( $entry ) ) {
					$this->add_note( 'Coupon creation aborted: required fields are empty.' );
					return true;
				}

				$coupon_code = $this->get_coupon_codes( $this->coupon_code_field, $form, $entry );

				if ( '' === $coupon_code ) {
					$this->add_note( 'No coupon codes found.' );
					return true;
				}

				// Resolve coupon name.
				if ( false === $this->name_field || '' === $this->name_field ) {
					$coupon_name = $coupon_code;
				} else {
					$coupon_name = $this->name_field;
					$coupon_name = GFCommon::replace_variables( $coupon_name, $form, $entry );
					$coupon_name = '' === $coupon_name ? $coupon_code : $coupon_name;
				}

				$amount = GFCommon::replace_variables( $this->amount, $form, $entry );
				$type   = $this->type;

				$meta = [
					'form_id'           => $target_form_id,
					'coupon_name'       => $coupon_name,
					'coupon_code'       => strtoupper( $coupon_code ),
					'coupon_type'       => $type,
					'coupon_amount'     => $amount,
					'coupon_start'      => $this->coupon_start_date,
					'coupon_expiration' => $this->coupon_expiration_date,
					'coupon_limit'      => GFCommon::replace_variables( $this->coupon_limit, $form, $entry ),
					'coupon_stackable'  => $this->stackable,
				];

				$final_code = $this->create_coupon( $meta, $target_form_id );
				if ( '' === $final_code ) {
					$this->add_note( 'Coupon creation failed.' );
					return true;
				}
				// Store the coupon code in entry meta for use with merge tags.
				$created_codes = $this->get_entry_coupon_codes( $eid );

				$created_codes[ (string) $target_form_id ] = $final_code;
				gform_update_meta( $eid, 'created_coupon_codes', $created_codes );
				$this->add_note( sprintf( 'Coupon created: %s', $final_code ) );

				return true;
			}

			/**
			 * Retrieves and processes coupon codes by replacing variables with their corresponding values.
			 *
			 * @param string $coupon_code_field The field containing the coupon code that needs processing.
			 * @param array  $form The form data where the coupon code field exists.
			 * @param array  $entry The entry data associated with the submitted form.
			 *
			 * @return string The processed coupon code with replaced variables.
			 */
			public function get_coupon_codes( $coupon_code_field, $form, $entry ) {
				return str_replace( ' ', '', GFCommon::replace_variables( $coupon_code_field, $form, $entry ) );
			}

			/**
			 * Check if the coupon creation should be aborted.
			 *
			 * @param array $entry The entry object.
			 * @return bool True if the coupon creation should be aborted.
			 */
			public function should_abort( $entry ) {
				$this->required_fields = array_filter( $this->required_fields );
				if ( empty( $this->required_fields ) ) {
					return false;
				}

				foreach ( $this->required_fields as $field_id ) {
					$value = rgar( $entry, (string) $field_id );

					if ( rgblank( $value ) ) {
						return true;
					}
				}

				return false;
			}

			/**
			 * Creates a coupon and associates it with a form in Gravity Forms.
			 *
			 * @param array $meta An array of coupon metadata, including details such as coupon name, code, amount, type, start date, expiration date, and usage limits.
			 * @param int   $form_id The ID of the form to associate the coupon with.
			 *
			 * @return string Coupon code used, or empty string on failure.
			 */
			public function create_coupon( $meta, $form_id ) {
				if ( ! class_exists( 'GFCoupons' ) ) {
					return '';
				}

				// Ensure Coupons config is loaded (compat guard for different versions).
				if ( is_callable( 'gf_coupons' ) ) {
					gf_coupons()->get_config( [ 'id' => 0 ], false );
				} else {
					/* @noinspection PhpDynamicAsStaticMethodCallInspection */
					GFCoupons::get_config( [ 'id' => 0 ], false );
				}

				// Duplicate detection + auto-increment suffix (bounded attempts)
				$base_code = isset( $meta['coupon_code'] ) ? (string) $meta['coupon_code'] : '';
				$code      = $base_code;
				$attempts  = 0;
				$max       = 25;
				if ( is_callable( 'gf_coupons' ) ) {
					while ( $attempts < $max ) {
						$existing = gf_coupons()->get_coupons_by_codes( [ $code ], (int) $form_id );
						if ( empty( $existing ) ) {
							break;
						}
						$attempts++;
						$code = $base_code . '-' . $attempts;
					}
				}
				if ( $attempts >= $max ) {
					return '';
				}

				if ( is_callable( 'gf_coupons' ) ) {
					$meta['gravityForm']      = $meta['form_id'] ?: 0; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
					$meta['couponName']       = $meta['coupon_name'];
					$meta['couponCode']       = $code;
					$meta['couponAmountType'] = $meta['coupon_type'];
					$meta['couponAmount']     = $meta['coupon_amount'];
					$meta['startDate']        = $meta['coupon_start'];
					$meta['endDate']          = $meta['coupon_expiration'];
					$meta['usageLimit']       = $meta['coupon_limit'];
					$meta['isStackable']      = $meta['coupon_stackable'];
					$meta['usageCount']       = 0;
					unset( $meta['form_id'] );
					gf_coupons()->insert_feed( $form_id, true, $meta );
					return $code;
				}

				/* @noinspection PhpUndefinedClassInspection */
				GFCouponsData::update_feed( 0, $form_id, true, $meta );
				return $code;
			}
		}

		Gravity_Flow_Steps::register( new BLD_CouponCodeWorkflowStep() );
	}
);

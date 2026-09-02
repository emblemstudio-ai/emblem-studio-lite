<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * The periods a form runs for (SER-1292) — account-gated, same as the single
 * window below it. Shares the form-edit scope ($f).
 *
 * A list rather than one window, so a form can run in September, stop, and run
 * again in November without anybody remembering to switch it back on.
 *
 * The native date and time inputs are the control here, with no calendar layered
 * over them: the browser already gives a picker, and this admin screen has no
 * equivalent of the SaaS's two-click range shortcut. The rules are the same
 * either way, because they live in ES_Form_Schedule rather than in a screen.
 *
 * ONE CLOCK. Every value is site time, the same clock `current_time( 'mysql' )`
 * reads, so nothing here converts anything.
 */
/** @var object $f The form being edited (from the form-edit scope). */

$es_periods = ( ! empty( $f->id ) && class_exists( 'ES_Form_Schedule' ) )
	? ES_Form_Schedule::for_form( (int) $f->id )
	: [];
$es_dormant = ( ! empty( $f->id ) && class_exists( 'ES_Form_Schedule' ) )
	? ES_Form_Schedule::dormant_ids( (int) $f->id )
	: [];
$es_current = ( ! empty( $f->id ) && class_exists( 'ES_Form_Schedule' ) )
	? ES_Form_Schedule::current( (int) $f->id )
	: null;
$es_now     = (string) current_time( 'mysql' );
?>
			<h2><?php esc_html_e( 'When this runs', 'emblem-studio' ); ?></h2>
			<p class="description" style="max-width:640px;">
				<?php esc_html_e( 'Leave this empty and the form is always on. Add a period to run it between two dates, and add more to run it again later. Times are this site’s own time.', 'emblem-studio' ); ?>
			</p>

			<div id="es-periods" style="max-width:640px;">
				<?php
				$es_i = 0;
				foreach ( $es_periods as $es_p ) :
					$es_from     = $es_p->starts_at ? substr( str_replace( ' ', 'T', (string) $es_p->starts_at ), 0, 16 ) : '';
					$es_until    = $es_p->ends_at ? substr( str_replace( ' ', 'T', (string) $es_p->ends_at ), 0, 16 ) : '';
					$es_is_now   = $es_current && (int) $es_current->id === (int) $es_p->id;
					$es_is_dorm  = in_array( (int) $es_p->id, array_map( 'intval', $es_dormant ), true );
					$es_finished = ! empty( $es_p->ends_at ) && strcmp( $es_now, (string) $es_p->ends_at ) > 0;
					?>
					<div class="es-period" style="margin:0 0 .6em; padding:.7em .9em; border:1px solid <?php echo $es_is_dorm ? '#dba617' : '#dcdcde'; ?>; background:<?php echo $es_is_dorm ? '#fcf9e8' : ( $es_finished ? '#f6f7f7' : '#fff' ); ?>;">
						<label style="margin-right:1em;">
							<?php esc_html_e( 'From', 'emblem-studio' ); ?>
							<input type="datetime-local" name="periods[<?php echo (int) $es_i; ?>][starts_at]" value="<?php echo esc_attr( $es_from ); ?>">
						</label>
						<label style="margin-right:1em;">
							<?php esc_html_e( 'To', 'emblem-studio' ); ?>
							<input type="datetime-local" name="periods[<?php echo (int) $es_i; ?>][ends_at]" value="<?php echo esc_attr( $es_until ); ?>">
						</label>
						<?php if ( $es_is_now ) : ?>
							<span class="description" style="color:#646970;"><?php esc_html_e( 'Running now, cannot be removed. Change its end date to stop the form.', 'emblem-studio' ); ?></span>
						<?php else : ?>
							<button type="button" class="button es-period-remove"><?php esc_html_e( 'Remove', 'emblem-studio' ); ?></button>
						<?php endif; ?>

						<?php if ( $es_is_dorm ) : ?>
							<p class="description" style="margin:.4em 0 0; color:#8a6100;">
								<?php esc_html_e( 'This will not run. An earlier period has no end date, so the form never reaches this one. Give that one an end date to start this.', 'emblem-studio' ); ?>
							</p>
						<?php elseif ( '' === $es_until && '' !== $es_from ) : ?>
							<p class="description" style="margin:.4em 0 0;">
								<?php esc_html_e( 'No end date, so this runs from then on. Anything added after it waits until you give it one.', 'emblem-studio' ); ?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $es_p->never_ran_reason ) ) : ?>
							<p class="description" style="margin:.4em 0 0; color:#8a6100;"><?php echo esc_html( sprintf( __( 'Never ran. %s', 'emblem-studio' ), $es_p->never_ran_reason ) ); ?></p>
						<?php endif; ?>
					</div>
					<?php
					++$es_i;
				endforeach;
				?>
			</div>

			<p>
				<button type="button" class="button" id="es-period-add"><?php esc_html_e( 'Add a period', 'emblem-studio' ); ?></button>
				<span class="description" style="margin-left:.5em;">
					<?php
					printf(
						/* translators: %d = the most periods allowed */
						esc_html__( 'Up to %d. Past that, what you want is a repeating rule rather than a list.', 'emblem-studio' ),
						(int) ES_Form_Schedule::MAX_PERIODS
					);
					?>
				</span>
			</p>

			<script>
			( function () {
				var wrap = document.getElementById( 'es-periods' );
				var add  = document.getElementById( 'es-period-add' );
				if ( ! wrap || ! add ) { return; }
				var max = <?php echo (int) ES_Form_Schedule::MAX_PERIODS; ?>;

				// The index only has to be unique within this submission; the
				// server renumbers by position when it saves.
				var next = wrap.querySelectorAll( '.es-period' ).length;

				add.addEventListener( 'click', function () {
					if ( wrap.querySelectorAll( '.es-period' ).length >= max ) { return; }
					var row = document.createElement( 'div' );
					row.className = 'es-period';
					row.setAttribute( 'style', 'margin:0 0 .6em; padding:.7em .9em; border:1px solid #dcdcde; background:#fff;' );
					row.innerHTML =
						'<label style="margin-right:1em;"><?php echo esc_js( __( 'From', 'emblem-studio' ) ); ?> ' +
						'<input type="datetime-local" name="periods[' + next + '][starts_at]"></label>' +
						'<label style="margin-right:1em;"><?php echo esc_js( __( 'To', 'emblem-studio' ) ); ?> ' +
						'<input type="datetime-local" name="periods[' + next + '][ends_at]"></label>' +
						'<button type="button" class="button es-period-remove"><?php echo esc_js( __( 'Remove', 'emblem-studio' ) ); ?></button>';
					wrap.appendChild( row );
					next++;
				} );

				wrap.addEventListener( 'click', function ( e ) {
					if ( ! e.target.classList.contains( 'es-period-remove' ) ) { return; }
					var row = e.target.closest( '.es-period' );
					if ( row ) { row.remove(); }
				} );
			} )();
			</script>

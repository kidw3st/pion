<?php
/**
 * Рубрики, метки, архивы по датам — та же лента карточек, что и на главной,
 * только с заголовком раздела.
 *
 * @package pion
 */

get_header();
?>

<main class="pion-main">
	<div class="pion-page-head">
		<h1 class="pion-page-title"><?php echo esc_html(get_the_archive_title()); ?></h1>
		<?php if (get_the_archive_description()) : ?>
			<p class="pion-page-subtitle"><?php echo wp_kses_post(get_the_archive_description()); ?></p>
		<?php endif; ?>
	</div>

	<?php if (have_posts()) : ?>
		<div class="pion-cards">
			<?php
			while (have_posts()) :
				the_post();
				get_template_part('template-parts/card');
			endwhile;
			?>
		</div>

		<?php
		the_posts_pagination([
			'class'     => 'pion-pagination',
			'mid_size'  => 2,
			'prev_text' => 'Назад',
			'next_text' => 'Дальше',
		]);
		?>
	<?php else : ?>
		<p class="pion-empty">В этом разделе пока нет заметок.</p>
	<?php endif; ?>
</main>

<?php get_footer(); ?>

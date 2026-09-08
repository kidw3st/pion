<?php
/**
 * Лента записей — главная страница блога.
 *
 * @package pion
 */

get_header();
?>

<main class="pion-main">
	<div class="pion-page-head">
		<h1 class="pion-page-title">Заметки флористов</h1>
		<?php if (get_bloginfo('description')) : ?>
			<p class="pion-page-subtitle"><?php echo esc_html(get_bloginfo('description')); ?></p>
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
		<p class="pion-empty">Здесь пока пусто — первые заметки скоро появятся.</p>
	<?php endif; ?>
</main>

<?php get_footer(); ?>

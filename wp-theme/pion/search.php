<?php
/**
 * Результаты поиска.
 *
 * @package pion
 */

get_header();
?>

<main class="pion-main">
	<div class="pion-page-head">
		<h1 class="pion-page-title">Поиск</h1>
		<?php if (get_search_query()) : ?>
			<p class="pion-page-subtitle">
				<?php
				printf(
					have_posts() ? 'Нашли по запросу «%s»' : 'Ничего не нашли по запросу «%s»',
					esc_html(get_search_query())
				);
				?>
			</p>
		<?php endif; ?>
		<?php get_search_form(); ?>
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
		<p class="pion-empty">Попробуйте другое слово — или загляните <a href="<?php echo esc_url(pion_site_link('/catalog')); ?>">в каталог</a>, там всё по полкам.</p>
	<?php endif; ?>
</main>

<?php get_footer(); ?>

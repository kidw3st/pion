<?php
/**
 * Несуществующий адрес внутри блога.
 *
 * @package pion
 */

get_header();
?>

<main class="pion-main">
	<div class="pion-page-head">
		<h1 class="pion-page-title">Страница не найдена</h1>
		<p class="pion-page-subtitle">
			Такой заметки нет — возможно, она переехала. Попробуйте поиск
			или вернитесь <a href="<?php echo esc_url(PION_SITE_URL . '/'); ?>">на сайт салона</a>.
		</p>
		<?php get_search_form(); ?>
	</div>

	<div class="pion-cta">
		<p>А пока — свежие букеты, собранные сегодня.</p>
		<div class="pion-cta-buttons">
			<a class="pion-btn" href="<?php echo esc_url(pion_site_link('/v-nalichii')); ?>">Букеты в наличии</a>
			<a class="pion-btn pion-btn-light" href="<?php echo esc_url(home_url('/')); ?>">Все заметки</a>
		</div>
	</div>
</main>

<?php get_footer(); ?>

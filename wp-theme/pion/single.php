<?php
/**
 * Отдельная запись.
 *
 * @package pion
 */

get_header();
?>

<main class="pion-main">
	<?php
	while (have_posts()) :
		the_post();
		$pion_cat = pion_primary_category();
		?>
		<article <?php post_class('pion-article'); ?>>
			<header class="pion-article-head">
				<p class="pion-article-meta">
					<?php if ($pion_cat) : ?>
						<a href="<?php echo esc_url(get_category_link($pion_cat)); ?>"><?php echo esc_html($pion_cat->name); ?></a>
						<span aria-hidden="true"> · </span>
					<?php endif; ?>
					<?php echo pion_posted_on(); // phpcs:ignore WordPress.Security.EscapeOutput -- собственная разметка времени ?>
				</p>
				<h1 class="pion-article-title"><?php the_title(); ?></h1>
			</header>

			<?php if (has_post_thumbnail()) : ?>
				<figure class="pion-article-cover">
					<?php the_post_thumbnail('pion-cover', ['fetchpriority' => 'high']); ?>
				</figure>
			<?php endif; ?>

			<div class="pion-content">
				<?php the_content(); ?>
			</div>
		</article>

		<?php
		// Блог существует ради заказов, поэтому выход в магазин должен быть под
		// каждой статьёй, а не только в шапке.
		?>
		<aside class="pion-cta">
			<p>Собираем букеты каждый день с 10:00 до 22:00 и привозим по Перми. Готовые букеты видно с фотографией и ценой — можно забрать сразу.</p>
			<div class="pion-cta-buttons">
				<a class="pion-btn" href="<?php echo esc_url(pion_site_link('/v-nalichii')); ?>">Букеты в наличии</a>
				<a class="pion-btn pion-btn-light" href="<?php echo esc_url(pion_site_link('/catalog')); ?>">Весь каталог</a>
			</div>
		</aside>

		<?php
		$pion_related = new WP_Query([
			'post__not_in'        => [get_the_ID()],
			'posts_per_page'      => 2,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		]);

		if ($pion_related->have_posts()) :
			?>
			<section class="pion-article-foot">
				<h2 class="pion-related-title">Другие заметки</h2>
				<ul class="pion-related">
					<?php
					while ($pion_related->have_posts()) :
						$pion_related->the_post();
						?>
						<li>
							<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							<?php echo pion_posted_on(); // phpcs:ignore WordPress.Security.EscapeOutput -- собственная разметка времени ?>
						</li>
					<?php endwhile; ?>
				</ul>
			</section>
			<?php
			wp_reset_postdata();
		endif;
		?>
	<?php endwhile; ?>
</main>

<?php get_footer(); ?>

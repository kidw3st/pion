<?php
/**
 * Карточка записи в ленте: фото 3:2, рубрика, заголовок, анонс.
 *
 * @package pion
 */

$pion_cat = pion_primary_category();
?>
<article <?php post_class('pion-card'); ?>>
	<?php if (has_post_thumbnail()) : ?>
		<a class="pion-card-photo" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<?php the_post_thumbnail('pion-card', ['loading' => 'lazy', 'alt' => '']); ?>
		</a>
	<?php endif; ?>

	<?php if ($pion_cat) : ?>
		<p class="pion-card-meta"><?php echo esc_html($pion_cat->name); ?></p>
	<?php endif; ?>

	<h2 class="pion-card-title">
		<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
	</h2>

	<p class="pion-card-excerpt"><?php echo esc_html(get_the_excerpt()); ?></p>

	<a class="pion-card-more" href="<?php the_permalink(); ?>">Читать дальше</a>
</article>

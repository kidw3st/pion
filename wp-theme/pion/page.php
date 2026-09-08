<?php
/**
 * Отдельная страница (не запись) — например, будущая «О блоге».
 *
 * @package pion
 */

get_header();
?>

<main class="pion-main">
	<?php
	while (have_posts()) :
		the_post();
		?>
		<article <?php post_class('pion-article'); ?>>
			<header class="pion-article-head">
				<h1 class="pion-article-title"><?php the_title(); ?></h1>
			</header>

			<?php if (has_post_thumbnail()) : ?>
				<figure class="pion-article-cover">
					<?php the_post_thumbnail('pion-cover'); ?>
				</figure>
			<?php endif; ?>

			<div class="pion-content">
				<?php the_content(); ?>
			</div>
		</article>
	<?php endwhile; ?>
</main>

<?php get_footer(); ?>

<?php
/**
 * Шапка блога — копия шапки основного сайта.
 *
 * @package pion
 */

$pion = pion_site_data();
$pion_tel = pion_tel($pion['phone']);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="pion-header">
	<a href="<?php echo esc_url(PION_SITE_URL . '/'); ?>" class="pion-logo" aria-label="Пион — на главную">
		<?php /* На узких экранах грузится знак без подписи: в картинке она нечитаема. */ ?>
		<picture>
			<source media="(max-width: 980px)" srcset="<?php echo esc_url(PION_SITE_URL . '/images/site/logo-mark.webp'); ?>">
			<img src="<?php echo esc_url(PION_SITE_URL . '/images/site/logo.webp'); ?>" alt="Пион" width="500" height="261">
		</picture>
		<span class="pion-logo-tagline" aria-hidden="true">Салон цветов<br>и подарков</span>
	</a>

	<nav class="pion-nav" aria-label="Основное меню">
		<ul class="pion-nav-list">
			<?php foreach ($pion['nav'] as $item) : ?>
				<?php
				$is_blog = str_starts_with((string) $item['href'], '/blog');
				$href    = $is_blog ? home_url('/') : pion_site_link((string) $item['href']);
				?>
				<li>
					<a href="<?php echo esc_url($href); ?>"<?php echo $is_blog ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html($item['label']); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<div class="pion-right">
		<div class="pion-contacts">
			<a href="tel:<?php echo esc_attr($pion_tel); ?>" class="pion-phone"><?php echo esc_html($pion['phone']); ?></a>
			<span class="pion-address"><?php echo esc_html($pion['address']); ?></span>
		</div>

		<ul class="pion-social">
			<?php foreach ($pion['social'] as $s) : ?>
				<li>
					<a href="<?php echo esc_url($s['href']); ?>" target="_blank" rel="noreferrer noopener" aria-label="<?php echo esc_attr($s['label']); ?>">
						<?php echo pion_social_icon($s['href']); // phpcs:ignore WordPress.Security.EscapeOutput -- собственная разметка иконки ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<a href="<?php echo esc_url(pion_site_link('/catalog')); ?>" class="pion-order-btn">СДЕЛАТЬ ЗАКАЗ</a>
	</div>

	<?php /* На телефонах строка выше скрыта, вместо неё — звонок и бургер. */ ?>
	<div class="pion-mobile">
		<a class="pion-call-btn" href="tel:<?php echo esc_attr($pion_tel); ?>" aria-label="Позвонить в салон">
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
				<path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24c1.12.37 2.33.57 3.6.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C11.4 21 3 12.6 3 2a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.27.2 2.48.57 3.6a1 1 0 0 1-.25 1l-2.22 2.2Z" fill="currentColor"/>
			</svg>
		</a>
		<button type="button" class="pion-burger" aria-label="Меню" aria-expanded="false" aria-controls="pion-panel">
			<span class="pion-bar pion-bar-top"></span>
			<span class="pion-bar pion-bar-mid"></span>
			<span class="pion-bar pion-bar-bottom"></span>
		</button>
	</div>
</header>

<?php /* Выдвижная панель. Скрыта через hidden, пока скрипт её не откроет. */ ?>
<div class="pion-overlay" data-pion-overlay hidden></div>
<div class="pion-panel" id="pion-panel" data-pion-panel hidden>
	<button type="button" class="pion-panel-close" aria-label="Закрыть меню">
		<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
			<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
		</svg>
	</button>

	<ul class="pion-panel-links">
		<?php foreach ($pion['nav'] as $item) : ?>
			<?php
			$is_blog = str_starts_with((string) $item['href'], '/blog');
			$href    = $is_blog ? home_url('/') : pion_site_link((string) $item['href']);
			?>
			<li>
				<a href="<?php echo esc_url($href); ?>"<?php echo $is_blog ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html($item['label']); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<a href="<?php echo esc_url(pion_site_link('/catalog')); ?>" class="pion-order-btn">СДЕЛАТЬ ЗАКАЗ</a>

	<div class="pion-panel-contacts">
		<a href="tel:<?php echo esc_attr($pion_tel); ?>" class="pion-panel-phone"><?php echo esc_html($pion['phone']); ?></a>
		<span class="pion-panel-address"><?php echo esc_html($pion['address']); ?></span>
	</div>

	<ul class="pion-panel-social">
		<?php foreach ($pion['social'] as $s) : ?>
			<li>
				<a href="<?php echo esc_url($s['href']); ?>" target="_blank" rel="noreferrer noopener" aria-label="<?php echo esc_attr($s['label']); ?>">
					<?php echo pion_social_icon($s['href']); // phpcs:ignore WordPress.Security.EscapeOutput -- собственная разметка иконки ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>

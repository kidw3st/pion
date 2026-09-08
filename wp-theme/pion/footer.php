<?php
/**
 * Подвал блога — копия подвала основного сайта.
 *
 * @package pion
 */

$pion = pion_site_data();
$pion_vk = '';

foreach ($pion['social'] as $s) {
	if (str_contains((string) $s['href'], 'vk.com')) {
		$pion_vk = (string) $s['href'];
		break;
	}
}
?>

<footer class="pion-footer">
	<div class="pion-footer-inner">
		<div class="pion-footer-brand">
			<a href="<?php echo esc_url(PION_SITE_URL . '/'); ?>" class="pion-footer-logo" aria-label="Пион — на главную">
				<img src="<?php echo esc_url(PION_SITE_URL . '/images/site/logo-footer.webp'); ?>" alt="Пион" width="938" height="490">
			</a>
			<p class="pion-footer-legal"><?php echo esc_html($pion['footer']['legal']); ?></p>
			<p class="pion-footer-legal"><?php echo esc_html($pion['footer']['hours']); ?></p>
		</div>

		<?php foreach ($pion['footer']['columns'] as $col) : ?>
			<div class="pion-footer-column">
				<h4 class="pion-footer-column-title"><?php echo esc_html($col['title']); ?></h4>
				<ul class="pion-footer-list">
					<?php foreach ($col['links'] as $l) : ?>
						<li><a href="<?php echo esc_url(pion_site_link((string) $l['href'])); ?>"><?php echo esc_html($l['label']); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>

		<div class="pion-footer-payment">
			<span>Оплата картой</span>
			<img src="<?php echo esc_url(PION_SITE_URL . '/images/site/mir.svg'); ?>" alt="МИР" width="70" height="47">
		</div>

		<?php if ($pion_vk !== '') : ?>
			<a href="<?php echo esc_url($pion_vk); ?>" target="_blank" rel="noreferrer noopener" class="pion-footer-vk">
				<span class="pion-footer-vk-text">ПЕРЕЙТИ<br>В ГРУППУ VK</span>
				<img class="pion-footer-vk-badge" src="<?php echo esc_url(PION_SITE_URL . '/images/site/pion-badge.svg'); ?>" alt="" width="52" height="53">
			</a>
		<?php endif; ?>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>

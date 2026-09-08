<?php
/**
 * Форма поиска по блогу.
 *
 * @package pion
 */
?>
<form role="search" method="get" class="pion-search" action="<?php echo esc_url(home_url('/')); ?>">
	<label class="pion-sr-only" for="pion-search-field">Поиск по блогу</label>
	<input type="search" id="pion-search-field" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="Что ищете?">
	<button type="submit">Найти</button>
</form>

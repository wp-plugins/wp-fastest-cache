<div class="wpfc-checkbox-list">
	<?php
		$types = array("css", "js", "gif", "png", "jpg", "jpeg", "ttf", "otf", "woff", "less", "mp4");

        foreach ($types as $key => $value) {
            ?>
            <label for="file-type-<?php echo $value; ?>">
                <input id="file-type-<?php echo $value; ?>" type="checkbox" value="<?php echo $value; ?>" checked=""><span class="">*.<?php echo $value; ?></span>
            </label>
            <?php
        }
	?>
</div>
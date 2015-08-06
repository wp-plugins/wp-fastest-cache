<div id="wpfc-modal-keycdn" style="top: 10.5px; left: 226px; position: absolute; padding: 6px; height: auto; width: 560px; z-index: 10001;">
	<div style="height: 100%; width: 100%; background: none repeat scroll 0% 0% rgb(0, 0, 0); position: absolute; top: 0px; left: 0px; z-index: -1; opacity: 0.5; border-radius: 8px;">
	</div>
	<div style="z-index: 600; border-radius: 3px;">
		<div style="font-family:Verdana,Geneva,Arial,Helvetica,sans-serif;font-size:12px;background: none repeat scroll 0px 0px rgb(255, 161, 0); z-index: 1000; position: relative; padding: 2px; border-bottom: 1px solid rgb(194, 122, 0); height: 35px; border-radius: 3px 3px 0px 0px;">
			<table width="100%" height="100%">
				<tbody>
					<tr>
						<td valign="middle" style="vertical-align: middle; font-weight: bold; color: rgb(255, 255, 255); text-shadow: 0px 1px 1px rgba(0, 0, 0, 0.5); padding-left: 10px; font-size: 13px; cursor: move;">KeyCDN Settings</td>
						<td width="20" align="center" style="vertical-align: middle;"></td>
						<td width="20" align="center" style="vertical-align: middle; font-family: Arial,Helvetica,sans-serif; color: rgb(170, 170, 170); cursor: default;">
							<div title="Close Window" class="close-wiz"></div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<div class="window-content-wrapper" style="padding: 8px;">
			<div style="z-index: 1000; height: auto; position: relative; display: inline-block; width: 100%;" class="window-content">


				<div id="wpfc-wizard-keycdn" class="wpfc-cdn-pages-container">
					<div wpfc-cdn-page="1" class="wiz-cont">
						<h1>Let's Get Started</h1>		
						<p>Hi! If you don't have a <strong>KeyCDN</strong> account, you can create one. If you already have, please continue...</p>
						<div class="wiz-input-cont" style="text-align:center;">
							<a href="https://www.keycdn.com/?a=5477" target="_blank">
								<button class="wpfc-green-button">Create a KeyCDN Account</button>
							</a>
					    </div>
					    <p class="wpfc-bottom-note" style="margin-bottom:-10px;"><a target="_blank" href="https://www.keycdn.com/support/wordpress-cdn-integration-with-wp-fastest-cache/">Note: Please read How to Integrate KeyCDN into WP Fastest Cache</a></p>
					</div>
					<div wpfc-cdn-page="2" class="wiz-cont" style="display:none">
						<h1>Enter CDN Url</h1>		
						<p>Please enter your <strong>KeyCDN CDN Url</strong> below to deliver your contents via KeyCDN.</p>
						<div class="wiz-input-cont">
							<label class="mc-input-label" for="cdn-url" style="padding-right: 12px;">Zone Url:</label><input type="text" name="" value="" class="api-key" id="cdn-url">
					    	<div id="cdn-url-loading"></div>
					    	<label class="wiz-error-msg"></label>
					    </div>
					    <div class="wiz-input-cont">
							<label class="mc-input-label" for="origin-url">Origin Url:</label><input type="text" name="" value="" class="api-key" id="origin-url">
					    </div>
					</div>
					<div wpfc-cdn-page="3" class="wiz-cont" style="display:none">
						<h1>File Types</h1>		
						<p>Specify the file types within the to host with the CDN.</p>
						
						<?php include "file_types.php"; ?> 
					</div>
					<div wpfc-cdn-page="4" class="wiz-cont" style="display:none">
						<h1>Ready to Go!</h1>
						<p>You're all set! Click the finish button below and that's it.</p>
					</div>
					<div wpfc-cdn-page="5" class="wiz-cont" style="display:none">
						<h1>Integration Ready!</h1>
						<p>Your static contents will be delivered via KeyCDN.</p>
					</div>
					<img class="wiz-bg-img" src="data:image/png;base64,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"/>
				</div>
			</div>
		</div>
		<?php include "../buttons.html"; ?> 
	</div>
</div>




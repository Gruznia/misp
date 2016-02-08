<div class="users form">
<?php echo $this->Form->create('User', array('novalidate'=>true));?>
	<fieldset>
		<legend><?php echo __('Admin Add User'); ?></legend>
	<?php
		echo $this->Form->input('email');
		echo $this->Form->input('password');
		echo $this->Form->input('confirm_password', array('type' => 'password', 'div' => array('class' => 'input password required')));
		if ($isSiteAdmin) {
			echo $this->Form->input('org_id', array(
					'options' => $orgs,
					'label' => 'Organisation',
					'empty' => 'Choose organisation',
			));
		}
		echo $this->Form->input('role_id', array('label' => 'Role', 'div' => 'input clear'));
		echo $this->Form->input('authkey', array('value' => $authkey, 'readonly' => 'readonly'));
		echo $this->Form->input('nids_sid');
	?>
		<div id = "syncServers" class="hidden">
	<?php 
			echo $this->Form->input('server_id', array('label' => 'Sync user for', 'div' => 'clear', 'options' => $servers));
	?>
		</div>
	<?php 
		echo $this->Form->input('gpgkey', array('label' => 'GPG key', 'div' => 'clear', 'class' => 'input-xxlarge'));
	?>
		<div class="clear"><span onClick="lookupPGPKey('UserEmail');" class="btn btn-inverse" style="margin-bottom:10px;">Fetch GPG key</span></div>
	<?php 
		echo $this->Form->input('autoalert', array('label' => 'Receive alerts when events are published'));
		echo $this->Form->input('contactalert', array('label' => 'Receive alerts from "contact reporter" requests'));
	?>
		<div class="clear"></div>
	<?php 
		echo $this->Form->input('disabled', array('label' => 'Disable this user account'));

	?>
	</fieldset>
<?php echo $this->Form->button(__('Submit'), array('class' => 'btn btn-primary'));
	echo $this->Form->end();?>
</div>
<?php 
	echo $this->element('side_menu', array('menuList' => 'admin', 'menuItem' => 'addUser'));
?>
<script type="text/javascript">
var syncRoles = <?php echo json_encode($syncRoles); ?>;
$(document).ready(function() {
	syncUserSelected();
	$('#UserRoleId').change(function() {
		syncUserSelected();
	});
});
</script>
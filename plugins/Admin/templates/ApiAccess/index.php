<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://www.foodcoopshop.com
 */

?>
<div class="filter-container">
    <h1><?php echo $title_for_layout; ?></h1>
</div>

<p><?php echo __('API_access_intro'); ?></p>

<?php if ($newToken) { ?>
    <div class="api-new-token" style="border:2px solid #d9a400;background:#fff8e1;padding:10px;margin:10px 0;">
        <b><?php echo __('New_API_token_shown_only_once'); ?></b><br>
        <code style="font-size:15px;user-select:all;"><?php echo h($newToken); ?></code>
    </div>
<?php } ?>

<table class="list">
    <tr class="sort">
        <th><?php echo __('Name'); ?></th>
        <th><?php echo __('Permissions'); ?></th>
        <th><?php echo __('API_tokens'); ?></th>
    </tr>
    <?php foreach ($rows as $row) {
        $c = $row['customer']; ?>
        <tr>
            <td>
                <?php echo h(trim($c->firstname . ' ' . $c->lastname)); ?>
                <?php if ($c->is_api_user) { ?><br><small><?php echo __('API_user'); ?></small><?php } ?>
            </td>
            <td>
                <?php echo $this->Form->create(null, ['url' => ['action' => 'savePermissions']]); ?>
                <?php echo $this->Form->hidden('customer_id', ['value' => $c->id_customer]); ?>
                <?php foreach ($allPermissions as $permission => $label) {
                    echo $this->Form->control('permissions.' . $permission, [
                        'type' => 'checkbox',
                        'label' => $label,
                        'checked' => in_array($permission, $row['permissions']),
                        'hiddenField' => false,
                    ]);
                } ?>
                <?php echo $this->Form->button(__('Save'), ['class' => 'btn btn-outline-light']); ?>
                <?php echo $this->Form->end(); ?>
            </td>
            <td>
                <?php foreach ($row['tokens'] as $token) { ?>
                    <div style="margin-bottom:4px;<?php echo $token->active ? '' : 'opacity:.5;'; ?>">
                        <?php echo h($token->name); ?> (…<?php echo h($token->token_hint); ?>)
                        <small>
                            <?php echo __('created'); ?> <?php echo $token->created->i18nFormat(\Cake\Core\Configure::read('app.timeHelper')->getI18Format('DateLong2')); ?>
                            <?php if ($token->last_used) { ?>, <?php echo __('last_used'); ?> <?php echo $token->last_used->i18nFormat(\Cake\Core\Configure::read('app.timeHelper')->getI18Format('DateNTimeShort')); ?><?php } ?>
                            <?php if ($token->expires) { ?>, <?php echo __('expires'); ?> <?php echo $token->expires->i18nFormat(\Cake\Core\Configure::read('app.timeHelper')->getI18Format('DateLong2')); ?><?php } ?>
                        </small>
                        <?php if ($token->active) {
                            echo $this->Form->create(null, ['url' => ['action' => 'revokeToken'], 'style' => 'display:inline']);
                            echo $this->Form->hidden('token_id', ['value' => $token->id]);
                            echo $this->Form->button(__('Revoke'), ['class' => 'btn btn-outline-light btn-sm']);
                            echo $this->Form->end();
                        } else { ?>
                            <i><?php echo __('revoked'); ?></i>
                        <?php } ?>
                    </div>
                <?php } ?>
                <?php echo $this->Form->create(null, ['url' => ['action' => 'createToken']]); ?>
                <?php echo $this->Form->hidden('customer_id', ['value' => $c->id_customer]); ?>
                <?php echo $this->Form->control('name', ['label' => false, 'placeholder' => __('Token_name'), 'required' => true]); ?>
                <?php echo $this->Form->control('expires', ['type' => 'date', 'label' => __('expires_optional')]); ?>
                <?php echo $this->Form->button(__('Create_token'), ['class' => 'btn btn-outline-light']); ?>
                <?php echo $this->Form->end(); ?>
            </td>
        </tr>
    <?php } ?>
</table>

<h2 style="margin-top:20px;"><?php echo __('Create_API_user'); ?></h2>
<?php echo $this->Form->create(null, ['url' => ['action' => 'createApiUser']]); ?>
<?php echo $this->Form->control('name', ['label' => __('Name'), 'placeholder' => 'LS-Zentrale']); ?>
<?php echo $this->Form->button(__('Create_API_user'), ['class' => 'btn btn-outline-light']); ?>
<?php echo $this->Form->end(); ?>

<h2 style="margin-top:20px;"><?php echo __('Grant_permissions_to_a_member'); ?></h2>
<?php echo $this->Form->create(null, ['url' => ['action' => 'savePermissions']]); ?>
<?php echo $this->Form->control('customer_id', ['type' => 'select', 'label' => __('Member'), 'options' => $customersForDropdown, 'empty' => '']); ?>
<?php foreach ($allPermissions as $permission => $label) {
    echo $this->Form->control('permissions.' . $permission, ['type' => 'checkbox', 'label' => $label, 'hiddenField' => false]);
} ?>
<?php echo $this->Form->button(__('Save'), ['class' => 'btn btn-outline-light']); ?>
<?php echo $this->Form->end(); ?>

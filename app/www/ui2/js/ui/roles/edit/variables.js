Ext.define('Scalr.ui.RoleDesignerTabVariables', {
    extend: 'Ext.container.Container',
    alias: 'widget.roleeditvariables',
    autoScroll: true,
    cls: 'x-container-fieldset x-panel-column-left',
    items: [{
        xtype: 'variablefield',
        name: 'variables',
        currentScope: 'role',
        addFieldCls: 'scalr-ui-addfield-light',
        maxWidth: 1300
    }],
    initComponent: function(){
        this.callParent(arguments);
        this.addListener({
            showtab: {
                fn: function(params){
                    this.down('variablefield').setValue(params['role']['variables']);
                },
                single: true
            }
        });
    },
    getSubmitValues: function() {
        return {variables: this.down('variablefield').getValue()};
    }
});

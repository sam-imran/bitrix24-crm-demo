document.addEventListener('click', function(event) {
    if (event.target.matches('.activities-link')) {
        event.preventDefault();
        showActivitiesDialog(event);
    }
});

function showActivitiesDialog(event) {
    var content = event.target.nextElementSibling.innerHTML;
    BX.UI.Dialogs.MessageBox.show({
        title: 'Дела',
        message: content,
        modal: true,
        minWidth: 800,
        maxWidth: 1200,
        popupOptions: {
            closeIcon: true,
            autoHide: true,
            closeByEsc: true,
            resizable: true,
            draggable: true
        }
    });
}

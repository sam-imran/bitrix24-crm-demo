BX.ready(function () {
    BX.addCustomEvent('BX.Main.Filter:apply', function (id, data, ctx) {
        if (id === 'LEADS_REPORT_FILTER') {
            let filterData = ctx.getFilterFieldsValues()
            let output = document.getElementById('result')
            output.innerHTML = 'Поиск...'

            BX.ajax.runComponentAction('imran:lead.report', 'getRowsCount', {
                data: { filter: filterData }
            }).then(function (response) {
                if (response ?.data ?.qty > 0) {
                    output.innerHTML = `<div> Найдено лидов: ${response.data.qty} </div>`;
                    // Добавляем кнопку "Скачать в Excel"
                    let downloadBtn = BX.create('a', {
                        attrs: {
                            href: '/local/components/imran/lead.report/?download=y',
                            className: 'ui-btn ui-btn-primary',
                            id: 'DOWNLOAD_EXCEL'
                        },
                        text: 'Скачать в Excel'
                    });
                    output.appendChild(downloadBtn);
                } else {
                    output.innerHTML = 'Лиды не найдены';
                }
            }, function () {
                output.innerHTML = 'Ошибка загрузки данных'
            })
        }
    });
    try {
        setTimeout(function () {
            BX.Main.filterManager.getById('LEADS_REPORT_FILTER').applyFilter()
        }, 500)
    } catch (error) {
        console.log(error)
    }
})
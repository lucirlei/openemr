(function ($) {
    $(function () {
        const rewardForm = $('form').filter(function () {
            return $(this).find('input[name="action"]').val() === 'award_points';
        });
        rewardForm.on('submit', function () {
            const points = parseInt($('#reward_points').val(), 10) || 0;
            if (points <= 0) {
                alert(window.xl ? xl('Informe um valor de pontos válido.') : 'Informe um valor de pontos válido.');
                return false;
            }
            return true;
        });

        const campaignTable = $('.campaign-table table');
        if (campaignTable.length && $.fn.DataTable) {
            campaignTable.DataTable({
                paging: false,
                searching: false,
                info: false,
                ordering: true,
                order: [[0, 'asc']],
                language: {
                    emptyTable: xl ? xl('Nenhum registro disponível') : 'Nenhum registro disponível'
                }
            });
        }
    });
})(window.jQuery);

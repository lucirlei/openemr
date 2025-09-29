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
                    emptyTable: (window.xl ? xl('Nenhum registro disponível') : 'Nenhum registro disponível')
                }
            });
        }

        const board = $('.crm-kanban-board');
        if (board.length && $.fn.sortable) {
            const csrf = board.data('csrf');
            const updateUrl = board.data('updateUrl');
            const successFallback = window.xl ? xl('Pipeline atualizado.') : 'Pipeline atualizado.';
            const errorFallback = window.xl ? xl('Não foi possível atualizar o pipeline.') : 'Não foi possível atualizar o pipeline.';
            const successMessage = board.data('successMessage') || successFallback;
            const errorMessage = board.data('errorMessage') || errorFallback;
            const feedback = board.siblings('.crm-kanban-feedback');
            let feedbackTimer = null;

            const showFeedback = function (type, message) {
                if (!feedback.length || !message) {
                    if (type === 'error') {
                        console.error(message);
                    }
                    return;
                }

                const feedbackClasses = ['alert-success', 'alert-danger', 'alert-warning', 'alert-info'];
                feedback.removeClass(feedbackClasses.join(' ')).removeClass('d-none');
                const styleClass = type === 'error' ? 'alert-danger' : 'alert-success';
                feedback.addClass(styleClass).text(message);

                if (feedbackTimer) {
                    clearTimeout(feedbackTimer);
                }
                feedbackTimer = setTimeout(function () {
                    feedback.addClass('d-none');
                }, 4000);
            };

            const updateColumnState = function ($column) {
                if (!$column || !$column.length) {
                    return;
                }
                const $body = $column.find('.crm-kanban-column-body').first();
                const $cards = $body.find('.crm-kanban-card');
                const $count = $column.find('.crm-kanban-column-count').first();
                const $empty = $body.find('.crm-kanban-empty').first();

                if ($count.length) {
                    $count.text($cards.length);
                }
                if ($empty.length) {
                    if ($cards.length === 0) {
                        $empty.removeClass('d-none');
                    } else {
                        $empty.addClass('d-none');
                    }
                }
            };

            const cleanupCardData = function ($card) {
                $card.removeData('originStage');
                $card.removeData('originColumn');
                $card.removeData('currentColumn');
            };

            const revertCard = function ($card) {
                const $originColumn = $card.data('originColumn');
                const originStage = $card.data('originStage');
                const $currentColumn = $card.data('currentColumn');

                if ($originColumn && $originColumn.length) {
                    const $originBody = $originColumn.find('.crm-kanban-column-body').first();
                    $originBody.append($card);
                    if (originStage) {
                        $card.data('stage', originStage);
                    }
                    updateColumnState($originColumn);
                }

                if ($currentColumn && $currentColumn.length && (!$originColumn || !$originColumn.is($currentColumn))) {
                    updateColumnState($currentColumn);
                }
            };

            board.find('.crm-kanban-column').each(function () {
                updateColumnState($(this));
            });

            board.find('.crm-kanban-column-body').sortable({
                connectWith: '.crm-kanban-column-body',
                items: '.crm-kanban-card',
                placeholder: 'crm-kanban-card-placeholder',
                forcePlaceholderSize: true,
                tolerance: 'pointer',
                start: function (event, ui) {
                    const $card = ui.item;
                    $card.addClass('crm-kanban-card-dragging');
                    $card.data('originStage', $card.data('stage'));
                    $card.data('originColumn', $card.closest('.crm-kanban-column'));
                },
                stop: function (event, ui) {
                    const $card = ui.item;
                    $card.removeClass('crm-kanban-card-dragging');
                    const $targetColumn = $card.closest('.crm-kanban-column');
                    $card.data('currentColumn', $targetColumn);
                    const newStage = $targetColumn.data('stage');
                    const originStage = $card.data('originStage');
                    const $originColumn = $card.data('originColumn');

                    updateColumnState($targetColumn);
                    if ($originColumn && !$originColumn.is($targetColumn)) {
                        updateColumnState($originColumn);
                    }

                    if (!newStage || !csrf || !updateUrl) {
                        revertCard($card);
                        cleanupCardData($card);
                        showFeedback('error', errorMessage);
                        return;
                    }

                    if (!originStage || newStage === originStage) {
                        $card.data('stage', newStage);
                        cleanupCardData($card);
                        return;
                    }

                    const leadUuid = $card.data('lead');
                    if (!leadUuid) {
                        cleanupCardData($card);
                        return;
                    }

                    $card.addClass('crm-kanban-card-updating');

                    $.ajax({
                        url: updateUrl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'update_pipeline',
                            crm_csrf: csrf,
                            lead_uuid: leadUuid,
                            new_stage: newStage,
                            ajax: 1
                        }
                    }).done(function (response) {
                        if (response && response.success) {
                            $card.data('stage', newStage);
                            showFeedback('success', response.message || successMessage);
                        } else {
                            const message = (response && response.message) ? response.message : errorMessage;
                            showFeedback('error', message);
                            revertCard($card);
                        }
                    }).fail(function () {
                        showFeedback('error', errorMessage);
                        revertCard($card);
                    }).always(function () {
                        $card.removeClass('crm-kanban-card-updating');
                        cleanupCardData($card);
                    });
                },
                receive: function () {
                    const $column = $(this).closest('.crm-kanban-column');
                    updateColumnState($column);
                },
                remove: function () {
                    const $column = $(this).closest('.crm-kanban-column');
                    updateColumnState($column);
                }
            }).disableSelection();
        }
    });
})(window.jQuery);

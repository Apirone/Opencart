$(function() {

    var countdown = $('#countdown').val();
    var status = $('.mccp-status');
    var current = $('#current').val();
    var url = $('#statusUrl').attr('href');
    var processId;
    var stopWatchId;

    async function getStat() {
        return $.ajax({url: url, dataType: 'text'});
    };
  
    function showCopyIcon (el) {
        $(el).addClass('copied');
        setTimeout(function() {
            $(el).removeClass('copied')
        },500);
    }
    function copy2clipboard(el) {
        if(navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(el.innerHTML)
            .then(showCopyIcon(el))
            .catch();
        }
        else {
            let textArea = document.createElement("textarea");
            textArea.value = el.innerHTML;
            textArea.style.position = "fixed";
            textArea.style.left = "-999999px";
            textArea.style.top = "-999999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            return new Promise((res, rej) => {
            document.execCommand('copy') ? res(el) : rej();
            textArea.remove();
            });
        }
    }

    function stopWatch() {
        let timer = $('#stopwatch');
        if (timer.length > 0 && countdown !== 'undefined') {
            $('#stopwatch').html(sec2human(countdown));
            if (countdown <= 0) {
                clearInterval(stopWatchId);
                document.location.reload();
                return;
            }
            countdown = countdown - 1;
        }
    }

    function sec2human(seconds) {
        if (seconds <= 0) {
            return '00:00';
        }
        return (seconds > 3600) ? new Date(seconds * 1000).toISOString().slice(11, 19) : new Date(seconds * 1000).toISOString().slice(14, 19);
    }


    async function init() {
        $('.date-gmt').each(function(){
            let gmt = new Date(this.innerHTML);
            let local = gmt.toLocaleString();
            if (local.includes('Invalid')) {
                this.innerHTML = this.innerHTML + " GMT";
            }
            else {
                this.innerHTML = local;
            }
        });
        $(".copy2clipboard").on('click', function(){
            copy2clipboard(this).then((el) => showCopyIcon(el)).catch(() => console.log('error copy'));
        });
        var stat = await getStat();
        async function process() {
            if (current === 0) {
            clearInterval(processId);
            return;
            }

            $(".loader-wrapper").addClass("loader-show");
            current = await getStat();
            if (current !== stat) {
                document.location.reload();
            }
            if (countdown < 0 && status === 'undefined') {
                setTimeout(() => document.location.reload(), 3000);
            }
            $(".loader-wrapper").removeClass("loader-show");
        };
        if (countdown !== 'undefined') {
            stopwatchId = setInterval(stopWatch, 1e3);
        }
        if (current > 0) {
            processId = setInterval(process, 1e4);
        }

    };
    init();
});

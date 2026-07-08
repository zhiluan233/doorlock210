const messagesContainer = document.getElementById('messages');
const input = document.getElementById('input');
const sendButton = document.getElementById('send');
var qaIdx = 0,answers={},answerContent='',answerWords=[];
var codeStart=false,lastWord='',lastLastWord='';
var typingTimer=null,typing=false,typingIdx=0,contentIdx=0,contentEnd=false;

//markdown解析，代码高亮设置
marked.setOptions({
    highlight: function (code, language) {
        const validLanguage = hljs.getLanguage(language) ? language : 'javascript';
        return hljs.highlight(code, { language: validLanguage }).value;
    },
});


//在输入时和获取焦点后自动调整输入框高度
input.addEventListener('input', adjustInputHeight);
input.addEventListener('focus', adjustInputHeight);

// 自动调整输入框高度
function adjustInputHeight() {
    input.style.height = 'auto'; // 将高度重置为 auto
    input.style.height = (input.scrollHeight+2) + 'px';
}

function sendMessage() {
    const inputValue = input.value;
    if (!inputValue) {
        return;
    }

    const question = document.createElement('div');
    question.setAttribute('class', 'message question');
    question.setAttribute('id', 'question-'+qaIdx);
    question.innerHTML = marked.parse(inputValue);
    messagesContainer.appendChild(question);

    const answer = document.createElement('div');
    answer.setAttribute('class', 'message answer');
    answer.setAttribute('id', 'answer-'+qaIdx);
    answer.innerHTML = marked.parse('AI思考中……');
    messagesContainer.appendChild(answer);

    answers[qaIdx] = document.getElementById('answer-'+qaIdx);

    input.value = '';
    input.disabled = true;
    sendButton.disabled = true;
    adjustInputHeight();

    typingTimer = setInterval(typingWords, 50);

    getAnswer(inputValue);
}

function getAnswer(inputValue){
    // 客户端代码
    // 创建XMLHttpRequest对象
    inputValue = encodeURIComponent(inputValue.replace(/\+/g, '{[$add$]}'));
    const url = "/api/YinyingV2R?message="+inputValue;
    if (checkCookie("chatyin_session")) {
        const eventSource = new EventSource(url, { withCredentials: true });
        // 监听EventSource事件
        eventSource.addEventListener("message", event => {
            // 处理返回的数据
            console.log(event.data);
        });
        /*        
        eventSource.addEventListener("message", (event) => {
            //console.log("接收数据：", event);
            try {
                var result = event.data;
                answerWords.push(result);
                contentIdx += 1;
            } catch (error) {
                console.log(error);
            }
        });
        */
                
        eventSource.addEventListener("error", (event) => {
            console.error("发生错误：", JSON.stringify(event));
        });
                
        eventSource.addEventListener("close", (event) => {
            console.log("连接已关闭", JSON.stringify(event.data));
            eventSource.close();
            contentEnd = true;
            console.log((new Date().getTime()), 'answer end');
        });
    }

}

function checkCookie(name) {
    // 获取当前页面的 cookie
    const cookies = document.cookie;
    // 使用 includes 方法检查 cookie 中是否包含指定的字段
    if (cookies.includes(name)) {
      console.log(`Cookie ${name} exists.`);
      return true;
    } else {
      console.log(`Cookie ${name} does not exist.`);
      return false;
    }
  }

function typingWords(){
    if(contentEnd && contentIdx==typingIdx){
        clearInterval(typingTimer);
        answerContent = '';
        answerWords = [];
        answers = [];
        qaIdx += 1;
        typingIdx = 0;
        contentIdx = 0;
        contentEnd = false;
        lastWord = '';
        lastLastWord = '';
        input.disabled = false;
        sendButton.disabled = false;
        console.log((new Date().getTime()), 'typing end');
        return;
    }
    if(contentIdx<=typingIdx){
        return;
    }
    if(typing){
        return;
    }
    typing = true;

    if(!answers[qaIdx]){
        answers[qaIdx] = document.getElementById('answer-'+qaIdx);
    }

    const content = answerWords[typingIdx];
    if(content.indexOf('`') != -1){
        if(content.indexOf('```') != -1){
            codeStart = !codeStart;
        }else if(content.indexOf('``') != -1 && (lastWord + content).indexOf('```') != -1){
            codeStart = !codeStart;
        }else if(content.indexOf('`') != -1 && (lastLastWord + lastWord + content).indexOf('```') != -1){
            codeStart = !codeStart;
        }
    }

    lastLastWord = lastWord;
    lastWord = content;

    answerContent += content;
    answers[qaIdx].innerHTML = marked.parse(answerContent+(codeStart?'\n\n```':''));

    typingIdx += 1;
    typing = false;
}

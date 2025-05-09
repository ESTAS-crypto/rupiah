<?php
// calculator_popup.php
?>

<style>
.overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    z-index: 1000;
}

.calculator-popup {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #ffffff;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 0 15px rgba(0, 0, 0, 0.2);
    width: 400px;
    border: 2px solid #007bff;
}

.calculator-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: move;
    padding-bottom: 10px;
    color: #007bff;
}

.close-btn {
    cursor: pointer;
    font-size: 24px;
    color: #007bff;
}

.currency-conversion {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}

.currency-conversion input,
.currency-conversion select {
    padding: 8px;
    font-size: 16px;
    border: 1px solid #007bff;
    border-radius: 5px;
    width: 45%;
}

.currency-conversion .arrow {
    font-size: 20px;
    color: #007bff;
}

.conversion-result {
    font-size: 18px;
    color: #007bff;
    margin: 10px 0;
}

.conversion-rate {
    font-size: 14px;
    color: #666;
}

.calculator-display {
    margin: 10px 0;
}

#display {
    width: 100%;
    padding: 10px;
    font-size: 24px;
    text-align: right;
    border: 1px solid #007bff;
    border-radius: 5px;
}

.calculator-buttons {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.calculator-buttons button {
    padding: 15px;
    font-size: 18px;
    cursor: pointer;
    background-color: #007bff;
    color: white;
    border: none;
    border-radius: 5px;
}

.calculator-buttons button:hover {
    background-color: #0056b3;
}

#mode-toggle {
    width: 100%;
    padding: 10px;
    margin-bottom: 10px;
    background-color: #007bff;
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
}

#mode-toggle:hover {
    background-color: #0056b3;
}

#send-money-btn {
    width: 100%;
    padding: 10px;
    background-color: #28a745;
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    margin-top: 10px;
}

#send-money-btn:hover {
    background-color: #218838;
}
</style>

<div id="overlay" class="overlay">
    <div id="calculator-popup" class="calculator-popup">
        <div class="calculator-header" id="calculator-header">
            <h3>Calculator</h3>
            <span class="close-btn" onclick="closeCalculator()">×</span>
        </div>
        <button id="mode-toggle">Switch to Currency Conversion</button>
        <div class="currency-conversion" style="display: none;">
            <input type="text" id="amount" oninput="convertCurrency()" placeholder="Jumlah">
            <select id="source-currency" onchange="convertCurrency()">
                <option value="USD">USD</option>
                <option value="IDR">IDR</option>
                <option value="EUR">EUR</option>
                <option value="JPY">JPY</option>
                <option value="GBP">GBP</option>
            </select>
            <span class="arrow">⇄</span>
            <input type="text" id="result" readonly>
            <select id="target-currency" onchange="convertCurrency()">
                <option value="IDR">IDR</option>
                <option value="USD">USD</option>
                <option value="EUR">EUR</option>
                <option value="JPY">JPY</option>
                <option value="GBP">GBP</option>
            </select>
        </div>
        <div class="conversion-result" style="display: none;"></div>
        <div class="conversion-rate" style="display: none;">Exchange rate at current time</div>
        <div class="calculator-display">
            <input type="text" id="display" readonly>
        </div>
        <div class="calculator-buttons">
            <button onclick="appendToDisplay('7')">7</button>
            <button onclick="appendToDisplay('8')">8</button>
            <button onclick="appendToDisplay('9')">9</button>
            <button onclick="setOperator('/')">/</button>
            <button onclick="appendToDisplay('4')">4</button>
            <button onclick="appendToDisplay('5')">5</button>
            <button onclick="appendToDisplay('6')">6</button>
            <button onclick="setOperator('*')">*</button>
            <button onclick="appendToDisplay('1')">1</button>
            <button onclick="appendToDisplay('2')">2</button>
            <button onclick="appendToDisplay('3')">3</button>
            <button onclick="setOperator('-')">-</button>
            <button onclick="appendToDisplay('0')">0</button>
            <button onclick="appendToDisplay('.')">.</button>
            <button onclick="calculate()">=</button>
            <button onclick="setOperator('+')">+</button>
            <button onclick="clearDisplay()">C</button>
        </div>
        <button id="send-money-btn" style="display: none;">Kirim uang</button>
    </div>
</div>

<script>
let currentInput = '';
let operator = '';
let firstOperand = null;
let isDragging = false;
let offsetX, offsetY;
let mode = 'calculator';
let latestRates = null;
const staticRates = {
    'USD': 1,
    'IDR': 16534.5,
    'EUR': 0.93,
    'JPY': 151.61,
    'GBP': 0.79
};

function appendToDisplay(value) {
    if (mode === 'calculator') {
        currentInput += value;
        document.getElementById('display').value = currentInput;
    }
}

function clearDisplay() {
    currentInput = '';
    operator = '';
    firstOperand = null;
    document.getElementById('display').value = '';
    document.getElementById('amount').value = '';
    document.getElementById('result').value = '';
    document.querySelector('.conversion-result').textContent = '';
}

function setOperator(op) {
    if (mode === 'calculator' && currentInput) {
        firstOperand = parseFloat(currentInput);
        operator = op;
        currentInput = '';
    }
}

function calculate() {
    if (mode === 'calculator') {
        if (firstOperand !== null && operator && currentInput) {
            let secondOperand = parseFloat(currentInput);
            let result;
            switch (operator) {
                case '+':
                    result = firstOperand + secondOperand;
                    break;
                case '-':
                    result = firstOperand - secondOperand;
                    break;
                case '*':
                    result = firstOperand * secondOperand;
                    break;
                case '/':
                    if (secondOperand === 0) {
                        result = 'Error';
                    } else {
                        result = firstOperand / secondOperand;
                    }
                    break;
            }
            document.getElementById('display').value = result.toFixed(2);
            currentInput = result.toString();
            firstOperand = null;
            operator = '';
        }
    }
}

async function convertCurrency() {
    let amount = parseFloat(document.getElementById('amount').value);
    if (isNaN(amount)) return;
    let sourceCurrency = document.getElementById('source-currency').value;
    let targetCurrency = document.getElementById('target-currency').value;
    const rates = latestRates || staticRates;
    if (rates[sourceCurrency] && rates[targetCurrency]) {
        let rate = rates[targetCurrency] / rates[sourceCurrency];
        let result = amount * rate;
        document.getElementById('result').value = result.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        document.querySelector('.conversion-result').textContent =
            `${amount.toLocaleString('en-US')} ${sourceCurrency} = ${result.toLocaleString('en-US')} ${targetCurrency}`;
        document.querySelector('.conversion-rate').textContent =
            `1 ${sourceCurrency} = ${rate.toFixed(4)} ${targetCurrency}`;
    } else {
        console.error('Currency not supported');
        document.querySelector('.conversion-result').textContent = 'Currency not supported';
    }
}

function openCalculator() {
    const popup = document.getElementById('calculator-popup');
    popup.style.top = '50%';
    popup.style.left = '50%';
    popup.style.transform = 'translate(-50%, -50%)';
    document.getElementById('overlay').style.display = 'block';
}

function closeCalculator() {
    document.getElementById('overlay').style.display = 'none';
}

document.getElementById('mode-toggle').addEventListener('click', async function() {
    clearDisplay();
    if (mode === 'calculator') {
        mode = 'conversion';
        try {
            const response = await fetch(
                'https://v6.exchangerate-api.com/v6/ad7dd02fd9d50358db0a4223/latest/USD');
            const data = await response.json();
            if (data.result === 'success') {
                latestRates = data.conversion_rates; // Use 'conversion_rates' as per API response
            } else {
                console.error('API error:', data);
            }
        } catch (error) {
            console.error('Fetch error:', error);
        }
        document.querySelector('.currency-conversion').style.display = 'flex';
        document.querySelector('.conversion-result').style.display = 'block';
        document.querySelector('.conversion-rate').style.display = 'block';
        document.querySelector('.calculator-display').style.display = 'none';
        document.querySelector('.calculator-buttons').style.display = 'none';
        document.getElementById('send-money-btn').style.display = 'block';
        this.textContent = 'Switch to Calculator';
    } else {
        mode = 'calculator';
        document.querySelector('.currency-conversion').style.display = 'none';
        document.querySelector('.conversion-result').style.display = 'none';
        document.querySelector('.conversion-rate').style.display = 'none';
        document.querySelector('.calculator-display').style.display = 'block';
        document.querySelector('.calculator-buttons').style.display = 'grid';
        document.getElementById('send-money-btn').style.display = 'none';
        this.textContent = 'Switch to Currency Conversion';
    }
});

document.getElementById('calculator-header').addEventListener('mousedown', function(e) {
    isDragging = true;
    const popup = document.getElementById('calculator-popup');
    const rect = popup.getBoundingClientRect();
    popup.style.left = rect.left + 'px';
    popup.style.top = rect.top + 'px';
    popup.style.transform = 'none';
    offsetX = e.clientX - rect.left;
    offsetY = e.clientY - rect.top;
});

document.addEventListener('mousemove', function(e) {
    if (isDragging) {
        const popup = document.getElementById('calculator-popup');
        let newLeft = e.clientX - offsetX;
        let newTop = e.clientY - offsetY;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const popupWidth = popup.offsetWidth;
        const popupHeight = popup.offsetHeight;
        if (newLeft < 0) newLeft = 0;
        if (newTop < 0) newTop = 0;
        if (newLeft + popupWidth > viewportWidth) newLeft = viewportWidth - popupWidth;
        if (newTop + popupHeight > viewportHeight) newTop = viewportHeight - popupHeight;
        popup.style.left = newLeft + 'px';
        popup.style.top = newTop + 'px';
    }
});

document.addEventListener('mouseup', function() {
    isDragging = false;
});

const overlay = document.getElementById('overlay');
overlay.addEventListener('click', function(e) {
    if (e.target === overlay) {
        closeCalculator();
    }
});

document.addEventListener('keydown', function(e) {
    const key = e.key;
    if (/[0-9]/.test(key)) {
        appendToDisplay(key);
    } else if (key === '.') {
        appendToDisplay('.');
    } else if (key === '+' || key === '-' || key === '*' || key === '/') {
        setOperator(key);
    } else if (key === 'Enter' || key === '=') {
        calculate();
    } else if (key === 'Escape' || key === 'c' || key === 'C') {
        clearDisplay();
    }
});
</script>
(function () {
    "use strict";

    var MONTHS = [
        "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    ];
    var WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    var today = new Date();
    today.setHours(0, 0, 0, 0);

    var activeInput = null;
    var activeButton = null;
    var viewMonth = today.getMonth();
    var viewYear = today.getFullYear() - 25;
    var previousBodyOverflow = "";

    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function toIso(date) {
        return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
    }

    function parseIso(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || "");
        if (!match) {
            return null;
        }

        var date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        date.setHours(0, 0, 0, 0);

        if (
            date.getFullYear() !== Number(match[1]) ||
            date.getMonth() !== Number(match[2]) - 1 ||
            date.getDate() !== Number(match[3])
        ) {
            return null;
        }

        return date;
    }

    function displayDate(value) {
        var date = parseIso(value);
        if (!date) {
            return "Choose date of birth";
        }

        return date.toLocaleDateString("en-US", {
            month: "long",
            day: "numeric",
            year: "numeric"
        });
    }

    var backdrop = document.createElement("div");
    backdrop.className = "birthday-picker-backdrop";
    backdrop.hidden = true;
    backdrop.innerHTML =
        '<section class="birthday-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="birthdayPickerTitle">' +
            '<div class="birthday-picker-heading">' +
                '<strong id="birthdayPickerTitle">Choose date of birth</strong>' +
                '<button type="button" class="birthday-picker-close" aria-label="Close date picker">&times;</button>' +
            '</div>' +
            '<div class="birthday-picker-controls">' +
                '<button type="button" class="birthday-picker-nav birthday-picker-prev" aria-label="Previous month">&#8249;</button>' +
                '<select class="birthday-picker-month" aria-label="Month"></select>' +
                '<input type="number" class="birthday-picker-year" min="1900" aria-label="Year">' +
                '<button type="button" class="birthday-picker-nav birthday-picker-next" aria-label="Next month">&#8250;</button>' +
            '</div>' +
            '<div class="birthday-picker-weekdays" aria-hidden="true"></div>' +
            '<div class="birthday-picker-days" role="grid"></div>' +
            '<p class="birthday-picker-help">Future dates cannot be selected.</p>' +
        '</section>';
    document.body.appendChild(backdrop);

    var dialog = backdrop.querySelector(".birthday-picker-dialog");
    var closeButton = backdrop.querySelector(".birthday-picker-close");
    var previousButton = backdrop.querySelector(".birthday-picker-prev");
    var nextButton = backdrop.querySelector(".birthday-picker-next");
    var monthSelect = backdrop.querySelector(".birthday-picker-month");
    var yearInput = backdrop.querySelector(".birthday-picker-year");
    var weekdays = backdrop.querySelector(".birthday-picker-weekdays");
    var days = backdrop.querySelector(".birthday-picker-days");

    MONTHS.forEach(function (month, index) {
        var option = document.createElement("option");
        option.value = String(index);
        option.textContent = month;
        monthSelect.appendChild(option);
    });

    WEEKDAYS.forEach(function (weekday) {
        var label = document.createElement("span");
        label.textContent = weekday;
        weekdays.appendChild(label);
    });

    yearInput.max = String(today.getFullYear());

    function render() {
        if (viewYear > today.getFullYear()) {
            viewYear = today.getFullYear();
        }
        if (viewYear === today.getFullYear() && viewMonth > today.getMonth()) {
            viewMonth = today.getMonth();
        }

        monthSelect.value = String(viewMonth);
        yearInput.value = String(viewYear);
        nextButton.disabled = viewYear === today.getFullYear() && viewMonth === today.getMonth();
        days.innerHTML = "";

        var firstWeekday = new Date(viewYear, viewMonth, 1).getDay();
        var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
        var selectedDate = activeInput ? parseIso(activeInput.value) : null;
        var blankIndex;
        var dayNumber;

        for (blankIndex = 0; blankIndex < firstWeekday; blankIndex += 1) {
            var blank = document.createElement("span");
            blank.className = "birthday-picker-blank";
            blank.setAttribute("aria-hidden", "true");
            days.appendChild(blank);
        }

        for (dayNumber = 1; dayNumber <= daysInMonth; dayNumber += 1) {
            addDay(dayNumber, selectedDate);
        }
    }

    function addDay(dateNumber, selectedDate) {
        var date = new Date(viewYear, viewMonth, dateNumber);
        date.setHours(0, 0, 0, 0);

        var button = document.createElement("button");
        button.type = "button";
        button.className = "birthday-picker-day";
        button.textContent = String(dateNumber);
        button.setAttribute("role", "gridcell");
        button.setAttribute("aria-label", MONTHS[viewMonth] + " " + dateNumber + ", " + viewYear);
        button.disabled = date > today;

        if (date.getTime() === today.getTime()) {
            button.classList.add("is-today");
        }

        if (selectedDate && date.getTime() === selectedDate.getTime()) {
            button.classList.add("is-selected");
            button.setAttribute("aria-selected", "true");
        }

        button.addEventListener("click", function () {
            if (!activeInput || date > today) {
                return;
            }

            activeInput.value = toIso(date);
            activeInput.dispatchEvent(new Event("input", { bubbles: true }));
            activeInput.dispatchEvent(new Event("change", { bubbles: true }));
            updateTrigger(activeInput, activeButton);
            closePicker();
        });

        days.appendChild(button);
    }

    function openPicker(input, button) {
        activeInput = input;
        activeButton = button;
        var selected = parseIso(input.value);
        var defaultDate = new Date(today.getFullYear() - 25, today.getMonth(), 1);
        var startingDate = selected && selected <= today ? selected : defaultDate;

        viewMonth = startingDate.getMonth();
        viewYear = startingDate.getFullYear();
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        backdrop.hidden = false;
        render();
        closeButton.focus();
    }

    function closePicker() {
        backdrop.hidden = true;
        document.body.style.overflow = previousBodyOverflow;
        if (activeButton) {
            activeButton.focus();
        }
        activeInput = null;
        activeButton = null;
    }

    function updateTrigger(input, button) {
        var value = parseIso(input.value);
        if (value && value <= today) {
            button.textContent = displayDate(input.value);
            button.classList.remove("is-empty", "is-invalid");
        } else {
            if (input.value) {
                input.value = "";
            }
            button.textContent = "Choose date of birth";
            button.classList.add("is-empty");
        }
    }

    function enhance(input) {
        if (input.dataset.birthdayPickerReady === "true") {
            return;
        }

        input.dataset.birthdayPickerReady = "true";
        input.classList.add("birthday-picker-native");
        input.type = "hidden";

        var button = document.createElement("button");
        button.type = "button";
        button.id = input.id ? input.id + "_picker_button" : "birthday_picker_" + Math.random().toString(36).slice(2);
        button.className = "birthday-picker-trigger";
        button.setAttribute("aria-haspopup", "dialog");
        button.setAttribute("aria-controls", "birthdayPickerTitle");
        input.insertAdjacentElement("afterend", button);

        if (input.id) {
            var label = document.querySelector('label[for="' + input.id.replace(/"/g, '\\"') + '"]');
            if (label) {
                label.htmlFor = button.id;
            }
        }

        updateTrigger(input, button);

        button.addEventListener("click", function () {
            openPicker(input, button);
        });

        input.addEventListener("change", function () {
            updateTrigger(input, button);
        });

        var form = input.form;
        if (form && input.required) {
            form.addEventListener("submit", function (event) {
                if (!parseIso(input.value)) {
                    event.preventDefault();
                    button.classList.add("is-invalid");
                    openPicker(input, button);
                }
            });
        }
    }

    previousButton.addEventListener("click", function () {
        viewMonth -= 1;
        if (viewMonth < 0) {
            viewMonth = 11;
            viewYear -= 1;
        }
        if (viewYear < 1900) {
            viewYear = 1900;
            viewMonth = 0;
        }
        render();
    });

    nextButton.addEventListener("click", function () {
        if (viewYear === today.getFullYear() && viewMonth === today.getMonth()) {
            return;
        }
        viewMonth += 1;
        if (viewMonth > 11) {
            viewMonth = 0;
            viewYear += 1;
        }
        render();
    });

    monthSelect.addEventListener("change", function () {
        viewMonth = Number(monthSelect.value);
        render();
    });

    yearInput.addEventListener("change", function () {
        var requestedYear = Number(yearInput.value);
        viewYear = Math.max(1900, Math.min(today.getFullYear(), requestedYear || today.getFullYear() - 25));
        render();
    });

    closeButton.addEventListener("click", closePicker);
    backdrop.addEventListener("click", function (event) {
        if (event.target === backdrop) {
            closePicker();
        }
    });
    dialog.addEventListener("click", function (event) {
        event.stopPropagation();
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !backdrop.hidden) {
            closePicker();
        }
    });

    document.querySelectorAll('input[type="date"][name="date_of_birth"], input[data-birthday-picker]').forEach(enhance);
}());

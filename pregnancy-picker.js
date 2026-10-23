(function () {
    "use strict";

    var MONTHS = [
        "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    ];
    var WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

    function startOfDay(date) {
        var copy = new Date(date);
        copy.setHours(0, 0, 0, 0);
        return copy;
    }

    function manilaToday() {
        var parts = new Intl.DateTimeFormat("en-US", {
            timeZone: "Asia/Manila",
            year: "numeric",
            month: "2-digit",
            day: "2-digit"
        }).formatToParts(new Date()).reduce(function (result, part) {
            if (part.type !== "literal") result[part.type] = part.value;
            return result;
        }, {});
        return new Date(Number(parts.year), Number(parts.month) - 1, Number(parts.day));
    }

    function parseYmd(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || "");
        if (!match) return null;
        var date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        date = startOfDay(date);
        return date.getFullYear() === Number(match[1])
            && date.getMonth() === Number(match[2]) - 1
            && date.getDate() === Number(match[3])
            ? date
            : null;
    }

    function toYmd(date) {
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, "0"),
            String(date.getDate()).padStart(2, "0")
        ].join("-");
    }

    function addMonths(date, months) {
        var result = new Date(date);
        result.setMonth(result.getMonth() + months);
        return startOfDay(result);
    }

    function formatDate(value) {
        var date = parseYmd(value);
        return date ? date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }) : "Not selected";
    }

    function dateKey(date) {
        return date.getFullYear() + "-" + date.getMonth();
    }

    function createMarkup() {
        var backdrop = document.createElement("div");
        backdrop.className = "pregnancy-picker-backdrop";
        backdrop.hidden = true;
        backdrop.innerHTML =
            '<section class="pregnancy-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="pregnancyPickerTitle">' +
                '<div class="pregnancy-picker-header">' +
                    '<div><h2 id="pregnancyPickerTitle">Pregnancy Details</h2><p>Select the dates you know. You may choose LMP, EDD, or both.</p></div>' +
                    '<button type="button" class="pregnancy-picker-close" aria-label="Close pregnancy details">&times;</button>' +
                '</div>' +
                '<div class="pregnancy-picker-calendars">' +
                    '<section class="pregnancy-picker-calendar" data-pregnancy-calendar="lmp" aria-labelledby="pregnancyLmpTitle">' +
                        '<div class="pregnancy-picker-calendar-head"><h3 id="pregnancyLmpTitle">Last Menstrual Period <span>(LMP)</span></h3></div>' +
                        '<div class="pregnancy-picker-month-bar"><button type="button" class="pregnancy-picker-nav" data-pregnancy-nav="prev" aria-label="Previous month">&#8249;</button><div class="pregnancy-picker-month-year"><select class="pregnancy-picker-month-select" aria-label="LMP month"></select><select class="pregnancy-picker-year-select" aria-label="LMP year"></select></div><button type="button" class="pregnancy-picker-nav" data-pregnancy-nav="next" aria-label="Next month">&#8250;</button></div>' +
                        '<div class="pregnancy-picker-weekdays" aria-hidden="true"></div><div class="pregnancy-picker-days" role="grid"></div>' +
                    '</section>' +
                    '<section class="pregnancy-picker-calendar" data-pregnancy-calendar="edd" aria-labelledby="pregnancyEddTitle">' +
                        '<div class="pregnancy-picker-calendar-head"><h3 id="pregnancyEddTitle">Estimated Date of Delivery <span>(EDD)</span></h3></div>' +
                        '<div class="pregnancy-picker-month-bar"><button type="button" class="pregnancy-picker-nav" data-pregnancy-nav="prev" aria-label="Previous month">&#8249;</button><div class="pregnancy-picker-month-year"><select class="pregnancy-picker-month-select" aria-label="EDD month"></select><select class="pregnancy-picker-year-select" aria-label="EDD year"></select></div><button type="button" class="pregnancy-picker-nav" data-pregnancy-nav="next" aria-label="Next month">&#8250;</button></div>' +
                        '<div class="pregnancy-picker-weekdays" aria-hidden="true"></div><div class="pregnancy-picker-days" role="grid"></div>' +
                    '</section>' +
                '</div>' +
                '<div class="pregnancy-picker-selected">' +
                    '<div><span>Last Menstrual Period (LMP)</span><strong data-pregnancy-selected="lmp">Not selected</strong></div>' +
                    '<div><span>Estimated Date of Delivery (EDD)</span><strong data-pregnancy-selected="edd">Not selected</strong></div>' +
                '</div>' +
                '<div class="pregnancy-picker-footer"><button type="button" class="pregnancy-picker-cancel">Cancel</button><button type="button" class="pregnancy-picker-set" disabled>Set Date</button></div>' +
            '</section>';
        document.body.appendChild(backdrop);
        return backdrop;
    }

    function init() {
        var gender = document.getElementById("gender");
        var row = document.getElementById("pregnancyFormRow");
        var trigger = document.getElementById("pregnancyPickerButton");
        var field = document.getElementById("pregnancyField");
        var statusInput = document.getElementById("pregnancy_status");
        var lmpInput = document.getElementById("pregnancy_lmp_date");
        var eddInput = document.getElementById("pregnancy_edd_date");
        var summary = document.getElementById("pregnancySummary");
        var clearButton = document.getElementById("pregnancyClear");
        var error = document.getElementById("pregnancyError");
        if (!gender || !row || !trigger || !statusInput || !lmpInput || !eddInput) return;

        var backdrop = createMarkup();
        var closeButton = backdrop.querySelector(".pregnancy-picker-close");
        var cancelButton = backdrop.querySelector(".pregnancy-picker-cancel");
        var setButton = backdrop.querySelector(".pregnancy-picker-set");
        var today = manilaToday();
        var allowedYears = [];
        var draft = { lmp: "", edd: "" };
        var views = { lmp: { month: today.getMonth(), year: today.getFullYear() }, edd: { month: today.getMonth(), year: today.getFullYear() } };
        var previousOverflow = "";

        function refreshYearRange() {
            var currentYear = today.getFullYear();
            allowedYears = [currentYear - 1, currentYear, currentYear + 1];
        }

        function isAllowedYear(year) {
            return allowedYears.indexOf(Number(year)) !== -1;
        }

        function clampView(kind) {
            var view = views[kind];
            if (view.year < allowedYears[0]) {
                view.year = allowedYears[0];
                view.month = 0;
            } else if (view.year > allowedYears[allowedYears.length - 1]) {
                view.year = allowedYears[allowedYears.length - 1];
                view.month = 11;
            }
        }

        function clearError() {
            if (error) {
                error.textContent = "";
                error.classList.remove("is-visible");
            }
        }

        function minimumEddDate() {
            var lmp = parseYmd(draft.lmp);
            return lmp ? addMonths(lmp, 7) : null;
        }

        function maximumEddDate() {
            var lmp = parseYmd(draft.lmp);
            return lmp ? addMonths(lmp, 10) : null;
        }

        function normalizeDraftDates() {
            var minimumEdd = minimumEddDate();
            var maximumEdd = maximumEddDate();
            var edd = parseYmd(draft.edd);
            if (edd && ((minimumEdd && edd < minimumEdd) || (maximumEdd && edd > maximumEdd))) {
                draft.edd = "";
            }
        }

        function updateSummary() {
            var lmp = parseYmd(lmpInput.value);
            var edd = parseYmd(eddInput.value);
            var hasDetails = statusInput.value === "Pregnant" && (lmp || edd);
            trigger.textContent = hasDetails ? "Pregnant" : "Select pregnancy details";
            trigger.classList.toggle("is-empty", !hasDetails);
            if (summary) {
                summary.hidden = !hasDetails;
                summary.innerHTML = hasDetails
                    ? (lmp ? "<span><strong>LMP:</strong> " + formatDate(lmpInput.value) + "</span>" : "")
                        + (edd ? "<span><strong>EDD:</strong> " + formatDate(eddInput.value) + "</span>" : "")
                    : "";
            }
            if (clearButton) clearButton.hidden = !hasDetails;
        }

        function syncGender() {
            var isFemale = gender.value === "Female";
            row.hidden = !isFemale;
            if (!isFemale) {
                statusInput.value = "";
                lmpInput.value = "";
                eddInput.value = "";
                closeModal();
            }
            updateSummary();
        }

        function renderSelected() {
            ["lmp", "edd"].forEach(function (kind) {
                var selected = backdrop.querySelector('[data-pregnancy-selected="' + kind + '"]');
                if (selected) selected.textContent = formatDate(draft[kind]);
            });
            setButton.disabled = !draft.lmp && !draft.edd;
        }

        function renderCalendar(kind) {
            var calendar = backdrop.querySelector('[data-pregnancy-calendar="' + kind + '"]');
            var view = views[kind];
            if (!calendar) return;
            var monthSelect = calendar.querySelector(".pregnancy-picker-month-select");
            var yearSelect = calendar.querySelector(".pregnancy-picker-year-select");
            var weekdays = calendar.querySelector(".pregnancy-picker-weekdays");
            var days = calendar.querySelector(".pregnancy-picker-days");
            clampView(kind);
            monthSelect.innerHTML = MONTHS.map(function (month, index) {
                return '<option value="' + index + '">' + month + '</option>';
            }).join("");
            monthSelect.value = String(view.month);
            yearSelect.innerHTML = allowedYears.map(function (year) {
                return '<option value="' + year + '">' + year + '</option>';
            }).join("");
            yearSelect.value = String(view.year);
            var previousButton = calendar.querySelector('[data-pregnancy-nav="prev"]');
            var nextButton = calendar.querySelector('[data-pregnancy-nav="next"]');
            previousButton.disabled = view.year === allowedYears[0] && view.month === 0;
            nextButton.disabled = view.year === allowedYears[allowedYears.length - 1] && view.month === 11;
            weekdays.innerHTML = WEEKDAYS.map(function (day) { return "<span>" + day + "</span>"; }).join("");
            days.innerHTML = "";

            var firstWeekday = new Date(view.year, view.month, 1).getDay();
            var daysInMonth = new Date(view.year, view.month + 1, 0).getDate();
            for (var i = 0; i < firstWeekday; i += 1) {
                days.appendChild(document.createElement("span"));
            }
            for (var dayNumber = 1; dayNumber <= daysInMonth; dayNumber += 1) {
                var date = startOfDay(new Date(view.year, view.month, dayNumber));
                var dateValue = toYmd(date);
                var button = document.createElement("button");
                button.type = "button";
                button.className = "pregnancy-picker-day";
                button.textContent = String(dayNumber);
                button.dataset.pregnancyDate = dateValue;
                button.setAttribute("aria-label", date.toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" }));
                if (draft[kind] === dateValue) button.classList.add("is-selected");
                if (kind === "lmp" && date > today) {
                    button.disabled = true;
                    button.title = "LMP cannot be a future date";
                }
                var minimumEdd = kind === "edd" ? minimumEddDate() : null;
                var maximumEdd = kind === "edd" ? maximumEddDate() : null;
                if (minimumEdd && date < minimumEdd) {
                    button.disabled = true;
                    button.title = "EDD must be at least 7 months after LMP";
                } else if (maximumEdd && date > maximumEdd) {
                    button.disabled = true;
                    button.title = "EDD cannot be more than 10 months after LMP";
                }
                button.addEventListener("click", function () {
                    draft[kind] = this.dataset.pregnancyDate;
                    if (kind === "lmp") normalizeDraftDates();
                    renderCalendar(kind);
                    if (kind === "lmp") renderCalendar("edd");
                    renderSelected();
                });
                days.appendChild(button);
            }
        }

        function renderCalendars() {
            renderCalendar("lmp");
            renderCalendar("edd");
            renderSelected();
        }

        function openModal() {
            today = manilaToday();
            refreshYearRange();
            draft.lmp = lmpInput.value;
            draft.edd = eddInput.value;
            normalizeDraftDates();
            ["lmp", "edd"].forEach(function (kind) {
                var selected = parseYmd(draft[kind]);
                views[kind] = selected && isAllowedYear(selected.getFullYear())
                    ? { month: selected.getMonth(), year: selected.getFullYear() }
                    : { month: today.getMonth(), year: today.getFullYear() };
            });
            renderCalendars();
            backdrop.hidden = false;
            trigger.setAttribute("aria-expanded", "true");
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = "hidden";
            closeButton.focus();
        }

        function closeModal() {
            backdrop.hidden = true;
            trigger.setAttribute("aria-expanded", "false");
            document.body.style.overflow = previousOverflow;
        }

        function setDates() {
            if (!draft.lmp && !draft.edd) return;
            statusInput.value = "Pregnant";
            lmpInput.value = draft.lmp;
            eddInput.value = draft.edd;
            clearError();
            updateSummary();
            closeModal();
        }

        function clearDates() {
            statusInput.value = "";
            lmpInput.value = "";
            eddInput.value = "";
            clearError();
            updateSummary();
            trigger.focus();
        }

        backdrop.querySelectorAll("[data-pregnancy-nav]").forEach(function (button) {
            button.addEventListener("click", function () {
                var calendar = button.closest("[data-pregnancy-calendar]");
                var kind = calendar.dataset.pregnancyCalendar;
                var direction = button.dataset.pregnancyNav === "next" ? 1 : -1;
                var next = new Date(views[kind].year, views[kind].month + direction, 1);
                if (!isAllowedYear(next.getFullYear())) return;
                views[kind] = { month: next.getMonth(), year: next.getFullYear() };
                renderCalendar(kind);
            });
        });
        backdrop.querySelectorAll(".pregnancy-picker-year-select").forEach(function (select) {
            select.addEventListener("change", function () {
                var calendar = select.closest("[data-pregnancy-calendar]");
                var kind = calendar.dataset.pregnancyCalendar;
                var year = Number(select.value);
                if (!isAllowedYear(year)) return;
                views[kind].year = year;
                renderCalendar(kind);
            });
        });
        backdrop.querySelectorAll(".pregnancy-picker-month-select").forEach(function (select) {
            select.addEventListener("change", function () {
                var calendar = select.closest("[data-pregnancy-calendar]");
                var kind = calendar.dataset.pregnancyCalendar;
                var month = Number(select.value);
                if (month < 0 || month > 11) return;
                views[kind].month = month;
                renderCalendar(kind);
            });
        });
        trigger.addEventListener("click", openModal);
        closeButton.addEventListener("click", closeModal);
        cancelButton.addEventListener("click", closeModal);
        setButton.addEventListener("click", setDates);
        if (clearButton) clearButton.addEventListener("click", clearDates);
        backdrop.addEventListener("click", function (event) {
            if (event.target === backdrop) closeModal();
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && !backdrop.hidden) closeModal();
        });
        gender.addEventListener("change", syncGender);
        [statusInput, lmpInput, eddInput].forEach(function (input) {
            input.addEventListener("input", updateSummary);
        });
        syncGender();
        updateSummary();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();

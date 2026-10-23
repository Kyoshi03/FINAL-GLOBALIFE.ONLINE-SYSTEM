<?php

function clinic_pregnancy_date_is_valid(string $value): bool
{
    if ($value === '') {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date instanceof DateTimeImmutable
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}
function clinic_pregnancy_validate(
    string $gender,
    string $status,
    string $lmpDate,
    string $eddDate
): string {
    $gender = ucfirst(strtolower(trim($gender)));
    $status = trim($status);
    $lmpDate = trim($lmpDate);
    $eddDate = trim($eddDate);

    if ($gender !== 'Female') {
        return $status === '' && $lmpDate === '' && $eddDate === ''
            ? ''
            : 'Pregnancy details can only be recorded for a female patient.';
    }

    if (!in_array($status, ['', 'Pregnant'], true)) {
        return 'Please choose a valid pregnancy status.';
    }

    if ($status === '' && ($lmpDate !== '' || $eddDate !== '')) {
        return 'Select Pregnancy Details before saving the pregnancy dates.';
    }

    if ($status === 'Pregnant' && $lmpDate === '' && $eddDate === '') {
        return 'Select at least one pregnancy date.';
    }

    if ($lmpDate !== '' && !clinic_pregnancy_date_is_valid($lmpDate)) {
        return 'Please select a valid Last Menstrual Period date.';
    }

    if ($eddDate !== '' && !clinic_pregnancy_date_is_valid($eddDate)) {
        return 'Please select a valid Estimated Date of Delivery.';
    }

    if ($lmpDate !== '' && $lmpDate > (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) {
        return 'The Last Menstrual Period cannot be a future date.';
    }

    if ($lmpDate !== '' && $eddDate !== '') {
        $lmp = DateTimeImmutable::createFromFormat('!Y-m-d', $lmpDate);
        $edd = DateTimeImmutable::createFromFormat('!Y-m-d', $eddDate);
        if ($lmp instanceof DateTimeImmutable && $edd instanceof DateTimeImmutable) {
            $minimumEdd = $lmp->modify('+7 months');
            $maximumEdd = $lmp->modify('+10 months');
            if ($edd < $minimumEdd) {
                return 'The Estimated Date of Delivery must be at least 7 months after the Last Menstrual Period.';
            }
            if ($edd > $maximumEdd) {
                return 'The Estimated Date of Delivery cannot be more than 10 months after the Last Menstrual Period.';
            }
        }
    }

    return '';
}

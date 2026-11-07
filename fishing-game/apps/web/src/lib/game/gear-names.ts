export function normalizeGearName(name: string): string {
  return name.replace(/^\p{White_Space}+|\p{White_Space}+$/gu, '');
}

export function gearNameValidationMessage(name: string): string {
  if (new TextEncoder().encode(name).length > 1024 || Array.from(normalizeGearName(name)).length > 32) {
    return 'Names can be up to 32 characters.';
  }
  if (/[\p{Cc}\u061c\u200e\u200f\u202a-\u202e\u2066-\u2069]/u.test(normalizeGearName(name))) {
    return 'Names cannot contain control or direction-changing characters.';
  }
  return '';
}

export function gearRenameErrorMessage(message: string): string {
  if (message.includes('GEAR_NAME_TOO_LONG')) return 'Names can be up to 32 characters.';
  if (message.includes('GEAR_NAME_INVALID')) return 'Names cannot contain control or direction-changing characters.';
  if (message.includes('GEAR_INSTANCE_UNAVAILABLE')) return 'This gear is no longer available. Refresh and try again.';
  return 'This name could not be saved. Please try again.';
}

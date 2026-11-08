import json
from pathlib import Path
import unittest
from database import capture_table, verify_table


def data(columns, rows):
    return {'schema': {'elements': [{'name': {'some': x}} for x in columns]}, 'rows': rows}


class Preservation(unittest.TestCase):
    def test_additive_defaults_and_new_rows_preserve_original_ownership_and_xp(self):
        old = data(['owner', 'xp'], [['boss', 18446744073709551615]])
        before = capture_table(old)
        verify_table(before, data(['new_default', 'xp', 'owner'],
            [[0, 18446744073709551615, 'boss'], [0, 1, 'new-player']]), 'player')

    def test_wallet_xp_ownership_changes_and_missing_rows_fail(self):
        before = capture_table(data(['owner', 'coins', 'xp'], [['boss', 100, 9007199254740993]]))
        for rows in [[['other', 100, 9007199254740993]], [['boss', 99, 9007199254740993]],
                     [['boss', 100, 9007199254740992]], []]:
            with self.assertRaises(RuntimeError):
                verify_table(before, data(['owner', 'coins', 'xp'], rows), 'player')

    def test_removed_columns_fail_even_with_same_row_count(self):
        before = capture_table(data(['owner', 'coins'], [['boss', 100]]))
        with self.assertRaises(RuntimeError):
            verify_table(before, data(['owner'], [['boss']]), 'player')

    def test_only_explicit_projection_changes_are_permitted(self):
        before = capture_table(data(['owner', 'level', 'xp'], [['boss', 3, 100]]), ['level'])
        verify_table(before, data(['owner', 'level', 'xp'], [['boss', 4, 100]]), 'profile')
        with self.assertRaises(RuntimeError):
            verify_table(before, data(['owner', 'level', 'xp'], [['boss', 4, 99]]), 'profile')

    def test_tideforge_curve_upgrade_retains_owner_family_and_exact_mastery_xp(self):
        release = json.loads(Path(__file__).with_name('release.json').read_text())
        fish = next(module for module in release['modules'] if module['key'] == 'fishModule')
        columns = ['key', 'player_id', 'kind', 'family_id', 'mastery_xp', 'curve_version']
        original = ['9:rod:2', 9, 'rod', 2, 9007199254740993, 0]
        before = capture_table(data(columns, [original]),
                               fish['mutableColumns']['gear_family_progress'])
        upgraded = original[:-1] + [1]
        verify_table(before, data(columns, [upgraded]), 'gear_family_progress')
        for position, changed in [(0, 'other'), (1, 10), (2, 'boat'),
                                  (3, 3), (4, 9007199254740992)]:
            corrupt = upgraded.copy()
            corrupt[position] = changed
            with self.assertRaises(RuntimeError):
                verify_table(before, data(columns, [corrupt]), 'gear_family_progress')
        with self.assertRaises(RuntimeError):
            verify_table(before, data(columns, []), 'gear_family_progress')


if __name__ == '__main__':
    unittest.main()

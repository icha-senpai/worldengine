import { mount } from '@vue/test-utils'
import LatestResultPanel from '@/Pages/ConnectedRealms/LatestResultPanel.vue'

describe('Connected Realms latest result panel', () => {
    it('shows the current level for the skill that just gained experience', () => {
        const wrapper = mount(LatestResultPanel, {
            props: {
                result: {
                    label: 'Fishing',
                    type: 'action',
                    skill: 'fishing',
                    skill_label: 'Fishing',
                    location: 'Moonwake Pier',
                    experience_awarded: 28,
                    gold_awarded: 4,
                    items_awarded: [],
                    skill_progress: {
                        skill: 'fishing',
                        skill_label: 'Fishing',
                        level: 4,
                        experience: 420,
                        current_level_experience: 367,
                        next_level_experience: 450,
                        experience_into_level: 53,
                        experience_to_next_level: 30,
                        max_level: 100,
                    },
                },
            },
        })

        expect(wrapper.text()).toContain('Skill Level')
        expect(wrapper.text()).toContain('Fishing Lv 4')
        expect(wrapper.text()).toContain('30 XP to Lv 5')
    })
})

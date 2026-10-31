import React from 'react';
import { TextInput, TextArea, CollapseSection, PageHeader, ImageUpload } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';
import type { TeamMember } from '../../types';
import { Users, Plus, Trash2, ArrowUp, ArrowDown } from 'lucide-react';

interface AboutAdminProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

/** Admin → About — controls the About page & homepage About / philosophy section. */
export const AboutAdmin: React.FC<AboutAdminProps> = ({ content, update }) => {
  const about = content.about || {};
  const team: TeamMember[] = Array.isArray(about.team) ? about.team : [];

  const patchMember = (idx: number, field: string, val: any) => {
    const next = [...team];
    if (field.startsWith('social.')) {
      const socialKey = field.split('.')[1] as 'instagram' | 'linkedin' | 'facebook' | 'twitter';
      next[idx] = {
        ...next[idx],
        social: {
          ...(next[idx].social || {}),
          [socialKey]: val,
        },
      };
    } else {
      next[idx] = { ...next[idx], [field]: val };
    }
    update(['about', 'team'], next);
  };

  const moveMember = (idx: number, direction: -1 | 1) => {
    const target = idx + direction;
    if (target < 0 || target >= team.length) return;
    const next = [...team];
    const [moved] = next.splice(idx, 1);
    next.splice(target, 0, moved);
    update(['about', 'team'], next);
  };

  const deleteMember = (idx: number) => {
    if (confirm(`Delete team member "${team[idx]?.name || 'this member'}"?`)) {
      update(['about', 'team'], team.filter((_, i) => i !== idx));
    }
  };

  const addMember = () => {
    const newMember: TeamMember = {
      id: `team-${Date.now()}`,
      name: '',
      role: '',
      department: 'Creative',
      image: '',
      bio: '',
      skills: ['Photography', 'Direction'],
      social: {
        instagram: '',
        linkedin: '',
      },
    };
    update(['about', 'team'], [...team, newMember]);
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="About Us & Team"
        description="Manage the studio story, philosophy copy, and the Creative Collective team members."
      />

      {/* ─── Story & Philosophy Copy ─── */}
      <CollapseSection title="Studio Story & Philosophy" defaultOpen>
        <TextArea
          label="Headline (line breaks allowed)"
          value={about.headline || ''}
          onChange={v => update(['about', 'headline'], v)}
          rows={3}
          placeholder="WE CREATE&#10;WHAT PEOPLE&#10;REMEMBER."
        />
        <TextArea
          label="Lead sentence (Primary Summary)"
          value={about.lead || ''}
          onChange={v => update(['about', 'lead'], v)}
          rows={2}
          placeholder="CreativeFX is a creative agency focused on turning ideas into meaningful visual experiences."
        />
        <TextArea
          label="Body Paragraph 1"
          value={about.body1 || ''}
          onChange={v => update(['about', 'body1'], v)}
          rows={3}
          placeholder="We believe great content is more than beautiful visuals..."
        />
        <TextArea
          label="Body Paragraph 2"
          value={about.body2 || ''}
          onChange={v => update(['about', 'body2'], v)}
          rows={3}
          placeholder="From businesses looking to build their online presence..."
        />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
          <TextArea
            label="Vision"
            value={about.vision || ''}
            onChange={v => update(['about', 'vision'], v)}
            rows={3}
          />
          <TextArea
            label="Mission"
            value={about.mission || ''}
            onChange={v => update(['about', 'mission'], v)}
            rows={3}
          />
        </div>
      </CollapseSection>

      {/* ─── Team Members Section ─── */}
      <CollapseSection
        title="Creative Team & Collective"
        badge={`${team.length} members`}
        defaultOpen
      >
        <div className="space-y-6">
          <p className="text-xs text-gray-500">
            Add your team members with portraits, names, roles, and descriptions. These appear on the <strong>/about</strong> page in the “Meet The Team” collective grid.
          </p>

          {team.map((member, idx) => (
            <div
              key={member.id || idx}
              className="border border-gray-200 rounded-lg p-5 space-y-4 bg-white shadow-xs"
            >
              {/* Member Header Bar */}
              <div className="flex items-center justify-between pb-3 border-b border-gray-100">
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-full bg-gray-100 border border-gray-200 flex items-center justify-center text-xs font-bold text-gray-700 uppercase overflow-hidden">
                    {member.image ? (
                      <img src={member.image} alt="" className="w-full h-full object-cover" />
                    ) : (
                      member.name ? member.name.slice(0, 2).toUpperCase() : `#${idx + 1}`
                    )}
                  </div>
                  <div>
                    <span className="text-sm font-semibold text-gray-900">
                      {member.name || `Team Member #${idx + 1}`}
                    </span>
                    {member.role && (
                      <span className="block text-[11px] text-gray-500 uppercase tracking-wider">
                        {member.role}
                      </span>
                    )}
                  </div>
                </div>

                <div className="flex items-center gap-1.5">
                  <button
                    type="button"
                    onClick={() => moveMember(idx, -1)}
                    disabled={idx === 0}
                    title="Move up"
                    className="p-1.5 text-xs bg-gray-50 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-30 cursor-pointer"
                  >
                    <ArrowUp className="w-3.5 h-3.5 text-gray-600" />
                  </button>
                  <button
                    type="button"
                    onClick={() => moveMember(idx, 1)}
                    disabled={idx === team.length - 1}
                    title="Move down"
                    className="p-1.5 text-xs bg-gray-50 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-30 cursor-pointer"
                  >
                    <ArrowDown className="w-3.5 h-3.5 text-gray-600" />
                  </button>
                  <button
                    type="button"
                    onClick={() => deleteMember(idx)}
                    title="Delete member"
                    className="p-1.5 text-red-600 hover:bg-red-50 rounded border border-red-200 cursor-pointer"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>

              {/* Photo Upload */}
              <ImageUpload
                label="Member Portrait / Photo"
                currentSrc={member.image || ''}
                onUpload={url => patchMember(idx, 'image', url)}
              />

              {/* Name & Role */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <TextInput
                  label="Full Name"
                  value={member.name || ''}
                  onChange={v => patchMember(idx, 'name', v)}
                  placeholder="e.g. Ashan Mendis"
                />
                <TextInput
                  label="Role / Title"
                  value={member.role || ''}
                  onChange={v => patchMember(idx, 'role', v)}
                  placeholder="e.g. FOUNDER & CREATIVE DIRECTOR"
                />
              </div>

              {/* Department & Skills */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <TextInput
                  label="Department / Focus"
                  value={member.department || ''}
                  onChange={v => patchMember(idx, 'department', v)}
                  placeholder="e.g. Direction & Vision"
                />
                <TextInput
                  label="Skills / Specialties (comma-separated)"
                  value={(member.skills || []).join(', ')}
                  onChange={v =>
                    patchMember(
                      idx,
                      'skills',
                      v.split(',').map(s => s.trim()).filter(Boolean)
                    )
                  }
                  placeholder="Creative Direction, 4K RAW, Color Science"
                />
              </div>

              {/* Bio Description */}
              <TextArea
                label="Bio / Description"
                value={member.bio || ''}
                onChange={v => patchMember(idx, 'bio', v)}
                rows={3}
                placeholder="Brief description of their role, expertise, or artistic vision..."
              />

              {/* Social Links */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1 border-t border-gray-100">
                <TextInput
                  label="Instagram Profile URL (optional)"
                  value={member.social?.instagram || ''}
                  onChange={v => patchMember(idx, 'social.instagram', v)}
                  placeholder="https://instagram.com/username"
                />
                <TextInput
                  label="LinkedIn Profile URL (optional)"
                  value={member.social?.linkedin || ''}
                  onChange={v => patchMember(idx, 'social.linkedin', v)}
                  placeholder="https://linkedin.com/in/username"
                />
              </div>
            </div>
          ))}

          {/* Add Team Member Button */}
          <button
            type="button"
            onClick={addMember}
            className="w-full flex items-center justify-center gap-2 border-2 border-dashed border-gray-300 rounded-lg py-3 text-sm font-medium text-gray-700 hover:border-gray-900 hover:text-gray-900 transition-colors cursor-pointer bg-gray-50/50 hover:bg-gray-50"
          >
            <Plus className="w-4 h-4" />
            <span>Add Team Member</span>
          </button>
        </div>
      </CollapseSection>

      {/* ─── Statistics Counters ─── */}
      <CollapseSection title="Statistics Counters">
        <div className="space-y-3">
          {(about.stats || []).map((stat: { value: string; label: string }, i: number) => (
            <div key={i} className="grid grid-cols-2 gap-3">
              <TextInput
                label={`Stat ${i + 1} Value`}
                value={stat.value || ''}
                onChange={v => update(['about', 'stats', String(i), 'value'], v)}
                placeholder="e.g. 100+"
              />
              <TextInput
                label={`Stat ${i + 1} Label`}
                value={stat.label || ''}
                onChange={v => update(['about', 'stats', String(i), 'label'], v)}
                placeholder="e.g. COMMERCIAL PROJECTS"
              />
            </div>
          ))}
        </div>
      </CollapseSection>
    </div>
  );
};

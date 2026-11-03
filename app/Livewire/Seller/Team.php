<?php

namespace App\Livewire\Seller;

use App\Models\SellerTeamActivity;
use App\Models\SellerTeamMember;
use App\Services\Seller\TeamService;
use App\Support\SellerContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.seller')]
class Team extends Component
{
    #[Validate('required|email|max:255')]
    public string $inviteEmail = '';

    #[Validate('required|in:manager,staff,viewer')]
    public string $inviteRole = 'staff';

    public ?int $editingId = null;
    public array $editAbilities = [];
    public string $editRole = 'staff';

    public function mount(): void
    {
        abort_unless(SellerContext::can('team.manage'), 403);
    }

    public function invite(TeamService $team): void
    {
        $this->validate();
        $team->invite(auth()->user(), $this->inviteEmail, $this->inviteRole);
        $this->reset('inviteEmail', 'inviteRole');
        $this->dispatch('toast', message: 'Invitation sent.');
    }

    public function startEdit(int $id): void
    {
        $m = $this->owned($id);
        $this->editingId = $m->id;
        $this->editRole = $m->role;
        $this->editAbilities = $m->abilities ?? [];
    }

    public function usePreset(string $role): void
    {
        $this->editRole = $role;
        $this->editAbilities = SellerTeamMember::presetAbilities($role);
    }

    public function saveEdit(TeamService $team): void
    {
        $m = $this->manageable($this->editingId);
        $role = $this->editRole === 'custom' ? 'custom' : $this->editRole;
        $abilities = array_values(array_intersect($this->editAbilities, array_keys(SellerTeamMember::ABILITIES)));

        // Only the store owner can hand out team management.
        if (in_array('team.manage', $abilities, true) && ! $this->actingAsOwner()) {
            $this->addError('editAbilities', 'Only the store owner can give someone team-management access.');

            return;
        }
        $team->updateRole($m, $role === 'custom' ? 'custom' : $role, $abilities);
        $this->editingId = null;
        $this->dispatch('toast', message: 'Permissions updated.');
    }

    public function setStatus(int $id, string $status, TeamService $team): void
    {
        $team->setStatus($this->manageable($id), $status);
        $this->dispatch('toast', message: 'Team member updated.');
    }

    public function resend(int $id, TeamService $team): void
    {
        $team->resend($this->owned($id));
        $this->dispatch('toast', message: 'Invitation resent.');
    }

    private function owned(int $id): SellerTeamMember
    {
        return SellerTeamMember::where('owner_id', auth()->id())->findOrFail($id);
    }

    /** A teammate this person may change — never their own access (no self-promotion). */
    private function manageable(int $id): SellerTeamMember
    {
        $m = $this->owned($id);
        abort_if($m->user_id && $m->user_id === SellerContext::actor()?->id, 403, 'You can\'t change your own access.');

        return $m;
    }

    private function actingAsOwner(): bool
    {
        return SellerContext::actor()?->id === auth()->id();
    }

    public function render()
    {
        return view('livewire.seller.team', [
            'members' => SellerTeamMember::where('owner_id', auth()->id())->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'invited' THEN 1 WHEN 'suspended' THEN 2 ELSE 3 END")->get(),
            'abilityCatalog' => SellerTeamMember::ABILITIES,
            'activity' => SellerTeamActivity::where('owner_id', auth()->id())->latest()->limit(20)->get(),
        ]);
    }
}

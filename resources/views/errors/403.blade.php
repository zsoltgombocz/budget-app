@extends('errors::minimal')

@section('title', __('Forbidden'))
@section('code', '403')
@section('message', __('Forbidden'))
@section('detail', __('You do not have access to this.'))
